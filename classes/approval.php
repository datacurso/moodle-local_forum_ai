<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../locallib.php');

/**
 * Class for approval request and notification handling.
 *
 * @package    local_forum_ai
 * @category   event
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class approval {
    /**
     * Generates an unpredictable approval token from a CSPRNG.
     *
     * Every path that issues a token (creation and course restore) must use this
     * factory so that no token is derived from time or process identifiers.
     *
     * @return string 64 lowercase hexadecimal characters.
     */
    public static function generate_approval_token(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Loads a pending AI response by token for the current user, enforcing access.
     *
     * Single entry point for every token-based page and web service: it resolves
     * the related records, then requires local/forum_ai:approveresponses in the
     * forum context and access to the discussion group (separate groups mode).
     *
     * @param string $token Approval token.
     * @param string|null $requirestatus Only match rows in this status, or any status when null.
     * @param int $strictness MUST_EXIST throws dml_missing_record_exception for an unknown
     *                        token; IGNORE_MISSING returns null instead. Related records
     *                        are always required.
     * @return \stdClass|null Object with pending, discussion, forum, course, cm and context.
     * @throws \required_capability_exception When the approval capability is missing.
     * @throws \moodle_exception error_discussionnotingroup when the discussion group is not accessible.
     */
    public static function load_pending_for_user(
        string $token,
        ?string $requirestatus = null,
        int $strictness = MUST_EXIST
    ): ?\stdClass {
        global $DB, $USER;

        $conditions = ['approval_token' => $token];
        if ($requirestatus !== null) {
            $conditions['status'] = $requirestatus;
        }

        $pending = $DB->get_record('local_forum_ai_pending', $conditions, '*', $strictness);
        if (!$pending) {
            return null;
        }

        $discussion = $DB->get_record('forum_discussions', ['id' => $pending->discussionid], '*', MUST_EXIST);
        $forum = $DB->get_record('forum', ['id' => $pending->forumid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $forum->course], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        require_capability('local/forum_ai:approveresponses', $context);
        local\group_access::require_discussion_access($cm, $course, (int) $discussion->groupid, (int) $USER->id);

        return (object) [
            'pending' => $pending,
            'discussion' => $discussion,
            'forum' => $forum,
            'course' => $course,
            'cm' => $cm,
            'context' => $context,
        ];
    }

    /**
     * Moves a pending AI response to its final state, exclusively and only once.
     *
     * Approve and reject requests for the same row are serialised with a lock;
     * under it the row is re-read and must still be pending with no published
     * post, so a request that passed the token gate before another one finished
     * is refused. The state is written field by field and only while the row is
     * pending, so it never overwrites what another writer stored.
     *
     * Failure ordering: when $publish throws before a post exists the row stays
     * pending; once a post exists publish_ai_post() has linked it to the row, so
     * any retry is refused by the postid check.
     *
     * @param int $pendingid Pending row id.
     * @param string $to Target state: 'approved' or 'rejected'.
     * @param int $actorid User who manages the response (stored in action_userid).
     * @param callable|null $publish Called under the lock with the fresh row before the state changes.
     * @return \stdClass The row after the transition.
     * @throws \moodle_exception error_responsebusy when another request holds the row,
     *                           error_responsenotpending when the row was already managed.
     */
    public static function transition_pending(int $pendingid, string $to, int $actorid, ?callable $publish = null): \stdClass {
        global $DB;

        if (!in_array($to, ['approved', 'rejected'], true)) {
            throw new \coding_exception('Invalid pending response transition: ' . $to);
        }

        return self::run_locked($pendingid, static function (\stdClass $pending) use ($DB, $to, $actorid, $publish): \stdClass {
            if ($pending->status !== 'pending' || !empty($pending->postid)) {
                throw new \moodle_exception('error_responsenotpending', 'local_forum_ai');
            }

            if ($publish !== null) {
                $publish($pending);
            }

            $now = time();
            $params = ['to' => $to, 'actorid' => $actorid, 'now' => $now, 'id' => $pending->id, 'pending' => 'pending'];
            $approvedat = '';
            if ($to === 'approved') {
                $approvedat = ', approved_at = :approvedat';
                $params['approvedat'] = $now;
            }
            // Record who managed the response without touching creator_userid:
            // the originating student's identity must survive for traceability.
            $DB->execute(
                "UPDATE {local_forum_ai_pending}
                    SET status = :to, action_userid = :actorid, timemodified = :now{$approvedat}
                  WHERE id = :id AND status = :pending",
                $params
            );

            return self::require_written_state((int) $pending->id, $to);
        });
    }

    /**
     * Replaces the message of a pending AI response, exclusively.
     *
     * Shares the lock of transition_pending(), so an edit never interleaves with
     * an approval. $compose receives the message stored when the lock was taken,
     * and the new message is written only while the row is still pending.
     *
     * @param int $pendingid Pending row id.
     * @param callable $compose Returns the purified message to store from the stored one.
     * @return \stdClass The row after the edit.
     * @throws \moodle_exception error_responsebusy when another request holds the row,
     *                           error_responsenotpending when the row is no longer pending.
     */
    public static function edit_pending_message(int $pendingid, callable $compose): \stdClass {
        global $DB;

        return self::run_locked($pendingid, static function (\stdClass $pending) use ($DB, $compose): \stdClass {
            // Only pending responses may be edited; approved or rejected history records are immutable.
            if ($pending->status !== 'pending') {
                throw new \moodle_exception('error_responsenotpending', 'local_forum_ai');
            }

            $message = (string) $compose((string) $pending->message);
            $DB->execute(
                "UPDATE {local_forum_ai_pending}
                    SET message = :message, timemodified = :now
                  WHERE id = :id AND status = :pending",
                ['message' => $message, 'now' => time(), 'id' => $pending->id, 'pending' => 'pending']
            );

            return self::require_written_state((int) $pending->id, 'pending');
        });
    }

    /**
     * Runs $callback with the freshly read pending row while holding its lock.
     *
     * @param int $pendingid Pending row id.
     * @param callable $callback Receives the pending row read under the lock.
     * @return mixed The callback result.
     * @throws \moodle_exception error_responsebusy when the lock cannot be obtained.
     */
    private static function run_locked(int $pendingid, callable $callback) {
        global $DB;

        $factory = \core\lock\lock_config::get_lock_factory('local_forum_ai');
        $lock = $factory->get_lock('pending_' . $pendingid, 5);
        if (!$lock) {
            throw new \moodle_exception('error_responsebusy', 'local_forum_ai');
        }

        try {
            $pending = $DB->get_record('local_forum_ai_pending', ['id' => $pendingid], '*', MUST_EXIST);
            return $callback($pending);
        } finally {
            $lock->release();
        }
    }

    /**
     * Re-reads a pending row and requires the state a conditional write stored.
     *
     * DML execute() does not report affected rows: a writer that ignored the lock
     * (bulk expiry) may have changed the row, which this re-read detects.
     *
     * @param int $pendingid Pending row id.
     * @param string $status Status the row must hold.
     * @return \stdClass The row.
     * @throws \moodle_exception error_responsenotpending when the row holds another status.
     */
    private static function require_written_state(int $pendingid, string $status): \stdClass {
        global $DB;

        $row = $DB->get_record('local_forum_ai_pending', ['id' => $pendingid], '*', MUST_EXIST);
        if ($row->status !== $status) {
            throw new \moodle_exception('error_responsenotpending', 'local_forum_ai');
        }
        return $row;
    }

    /**
     * Creates an approval request and sends a notification.
     *
     * @param object $discussion The discussion object.
     * @param object $forum The forum object.
     * @param string $message The AI-generated message.
     * @param string $status The approval status ('pending' or 'approved'). Defaults to 'pending'.
     * @param int|null $parentpostid The ID of the parent post to reply to, or null if top-level.
     * @param int|null $grade AI-generated grade, if applicable.
     * @param int|null $creatoruserid User ID to attribute as creator in pending/history.
     * @param int|null $actionuserid User ID that managed the response (the grader in automatic mode).
     * @return int The new pending row id, or 0 on failure.
     */
    public static function create_approval_request(
        $discussion,
        $forum,
        string $message,
        string $status = 'pending',
        ?int $parentpostid = null,
        ?int $grade = null,
        ?int $creatoruserid = null,
        ?int $actionuserid = null
    ): int {
        global $DB;

        try {
            $approvaltoken = self::generate_approval_token();

            $pending = new \stdClass();
            $pending->discussionid = $discussion->id;
            $pending->forumid = $forum->id;
            $pending->creator_userid = $creatoruserid ?? $discussion->userid;
            $pending->subject = "Re: " . $discussion->name;
            // The AI response is external, untrusted content: purify it before storing.
            $pending->message = clean_text($message, FORMAT_HTML);
            $pending->status = $status;
            $pending->approval_token = $approvaltoken;
            $pending->parentpostid = $parentpostid;
            $pending->timecreated = time();

            // Automatic mode: record the grader as manager, never as creator.
            if ($actionuserid !== null) {
                $pending->action_userid = $actionuserid;
                if ($status === 'approved') {
                    $pending->approved_at = $pending->timecreated;
                }
            }

            if ($forum->assessed != 0 && $grade !== null) {
                $pending->grade = $grade;
            }

            $pendingid = $DB->insert_record('local_forum_ai_pending', $pending);

            if ($status === 'pending') {
                self::send_moodle_notification($discussion, $forum, $pendingid, $approvaltoken);
            }

            return (int) $pendingid;
        } catch (\Exception $e) {
            debugging('Error in create_approval_request: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 0;
        }
    }

    /**
     * Sends a notification using Moodle's messaging system.
     *
     * @param object $discussion The discussion object.
     * @param object $forum The forum object.
     * @param int $pendingid The pending approval ID.
     * @param string $approvaltoken The unique approval token.
     * @return bool True on success, false on error.
     */
    public static function send_moodle_notification($discussion, $forum, int $pendingid, string $approvaltoken): bool {
        global $DB, $PAGE;

        try {
            $creator = $DB->get_record('user', ['id' => $discussion->userid]);
            $course = $DB->get_record('course', ['id' => $forum->course]);
            $pending = $DB->get_record('local_forum_ai_pending', ['id' => $pendingid]);

            if (!$creator || !$forum || !$course || !$pending) {
                return false;
            }

            $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);
            $context = \context_module::instance($cm->id);
            // Recipients are chosen by capability, never by role shortname, so custom or
            // renamed roles holding the approval permission are notified as well.
            $approvers = get_users_by_capability($context, 'local/forum_ai:approveresponses', 'u.*');
            $repliers = get_users_by_capability($context, 'mod/forum:replypost', 'u.id');

            // The review link and preview must only reach approvers who can access
            // the discussion group and could publish there on manual approval
            // (the same group rules approve_response and publish_ai_post apply).
            $discussiongroupid = isset($discussion->groupid)
                ? (int) $discussion->groupid
                : (int) $DB->get_field('forum_discussions', 'groupid', ['id' => $discussion->id], MUST_EXIST);
            $groupdiscussion = (object) ['groupid' => $discussiongroupid];

            $finalrecipients = [];
            foreach ($approvers as $approver) {
                if (!empty($approver->suspended) || !isset($repliers[$approver->id])) {
                    continue;
                }
                if (!local\group_access::can_access_discussion($cm, $course, $discussiongroupid, (int) $approver->id)) {
                    continue;
                }
                if (!utils::can_user_reply_in_discussion_group($cm, $course, $groupdiscussion, (int) $approver->id)) {
                    continue;
                }
                $finalrecipients[$approver->id] = $approver;
            }

            if (empty($finalrecipients)) {
                return false;
            }

            $reviewurl = new \moodle_url('/local/forum_ai/review.php', ['token' => $approvaltoken]);

            $renderer = null;
            try {
                $renderer = $PAGE->get_renderer('local_forum_ai');
            } catch (\Exception $e) {
                $renderer = null;
            }

            $preview = format_string(substr(strip_tags($pending->message), 0, 150));

            foreach ($finalrecipients as $recipient) {
                $message = new \core\message\message();
                $message->component = 'local_forum_ai';
                $message->name = 'ai_approval_request';
                $message->userfrom = \core_user::get_noreply_user();
                $message->userto = $recipient;
                $message->subject = get_string('notification_subject', 'local_forum_ai');

                $templatedata = [
                    'str_greeting' => get_string('notification_greeting', 'local_forum_ai', ['firstname' => $recipient->firstname]),
                    'discussionname' => $discussion->name,
                    'forumname' => $forum->name,
                    'preview' => $preview,
                    'reviewurl' => $reviewurl->out(false),
                    'coursefullname' => $course->fullname,
                    'str_subject' => get_string('notification_subject', 'local_forum_ai'),
                    'str_preview_label' => get_string('notification_preview', 'local_forum_ai'),
                    'str_review_button' => get_string('notification_review_button', 'local_forum_ai'),
                    'str_course_label' => get_string('notification_course_label', 'local_forum_ai'),
                ];

                // Generate plain text message using single string with all parameters.
                $message->fullmessage = get_string('notification_fullmessage', 'local_forum_ai', [
                    'firstname' => $recipient->firstname,
                    'discussion' => $discussion->name,
                    'forum' => $forum->name,
                    'course' => $course->fullname,
                    'preview' => $preview,
                    'reviewurl' => $reviewurl->out(false),
                ]);

                $message->fullmessageformat = FORMAT_PLAIN;

                if ($renderer) {
                    $message->fullmessagehtml = $renderer->render_from_template(
                        'local_forum_ai/notification',
                        $templatedata
                    );
                } else {
                    $message->fullmessagehtml = $message->fullmessage;
                }

                $message->smallmessage = get_string(
                    'notification_smallmessage',
                    'local_forum_ai',
                    ['discussion' => $discussion->name]
                );

                $message->contexturl = $reviewurl;
                $message->contexturlname = get_string('notification_review_button', 'local_forum_ai');

                message_send($message);
            }

            return true;
        } catch (\Exception $e) {
            debugging('Error sending Moodle notification: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Publishes an AI response as a forum post through the standard forum flow.
     *
     * Single publisher for both the automatic and the manual approval modes,
     * mirroring core's canonical non-HTTP caller (the forum inbound reply
     * handler): forum_add_new_post() followed by the post_created event and
     * the completion state update, which core leaves to the caller.
     *
     * forum_add_new_post() attributes the post to $USER, so when the caller
     * runs as another user (task/queue paths run as admin or the student) the
     * publisher switches to the author and restores the original user after.
     * The web approval path never switches ($USER is already the author);
     * \core\cron::setup_user() is CLI-only.
     *
     * @param \stdClass $discussion Discussion record.
     * @param \stdClass $forum Forum record.
     * @param \stdClass $cm Course module record.
     * @param \stdClass $course Course record.
     * @param \stdClass $pending Pending AI response row.
     * @param int $parentpostid ID of the post being replied to.
     * @param int $authorid User the published post is attributed to.
     * @return int|false The new post id, or false when publication is not possible.
     *                   False is only returned BEFORE any post is created (private
     *                   parent, missing/suspended/deleted author, or an author who
     *                   cannot reply in the discussion group). Once
     *                   forum_add_new_post() succeeds this method always returns the
     *                   new post id and no exception escapes: follow-up failures
     *                   (linking, event, completion) are logged and swallowed so
     *                   retries can never publish duplicate posts.
     */
    public static function publish_ai_post(
        \stdClass $discussion,
        \stdClass $forum,
        \stdClass $cm,
        \stdClass $course,
        \stdClass $pending,
        int $parentpostid,
        int $authorid
    ) {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/mod/forum/lib.php');

        // Resolve the effective parent; fall back to the first post when missing.
        $parentpost = $DB->get_record('forum_posts', [
            'id' => $parentpostid,
            'discussion' => $discussion->id,
        ]);
        if (!$parentpost) {
            debugging(
                'Parent post ID ' . $parentpostid . ' not found, using firstpost instead',
                DEBUG_DEVELOPER
            );
            // The first post of a discussion is never a private reply.
            $parentpostid = (int) $discussion->firstpost;
        } else if (utils::is_private_reply($parentpost)) {
            // Core forbids replying to private replies (forum_add_new_post would throw).
            debugging(
                'Cannot publish AI reply: parent post ' . $parentpostid . ' is a private reply',
                DEBUG_DEVELOPER
            );
            return false;
        }

        // The author userid is deliberately omitted: forum_add_new_post() hard-overrides
        // it with $USER->id, which is why the publisher switches users below instead.
        $post = new \stdClass();
        $post->discussion = $discussion->id;
        $post->parent = $parentpostid;
        $post->subject = $pending->subject ?: ('Re: ' . $discussion->name);
        // The AI response is external, untrusted content: purify it and never mark it as trusted.
        $post->message = clean_text($pending->message, FORMAT_HTML);
        $post->messageformat = FORMAT_HTML;
        $post->messagetrust = 0;
        // No draft file area is involved: skip the draft file merge entirely.
        $post->itemid = IGNORE_FILE_MERGE;
        $post->mailnow = 0;
        $post->deleted = 0;

        // The author differs from the current user on task/queue paths only.
        $author = null;
        if ((int) $USER->id !== $authorid) {
            $author = \core_user::get_user($authorid);
            if (!$author || !empty($author->deleted) || !empty($author->suspended)) {
                // Mirrors core's require_active_user() intent: a suspended or deleted
                // grader must not keep publishing, and a missing one must not cause
                // unbounded adhoc retries that re-call the paid AI service.
                debugging(
                    'Cannot publish AI reply: author user ' . $authorid . ' is missing or inactive',
                    DEBUG_DEVELOPER
                );
                return false;
            }
        }

        // Applies to the manual approval path too ($USER is the author there):
        // core would forbid this user from replying in the discussion group.
        if (!utils::can_user_reply_in_discussion_group($cm, $course, $discussion, $authorid)) {
            debugging(
                'Cannot publish AI reply: author user ' . $authorid . ' cannot reply in the group of discussion ' .
                    $discussion->id,
                DEBUG_DEVELOPER
            );
            return false;
        }

        // Switch to the author when the current user differs.
        $originaluser = null;
        if ($author !== null) {
            $originaluser = $USER;
            \core\cron::setup_user($author);
        }

        try {
            $postid = (int) forum_add_new_post($post, null);

            try {
                // Follow-ups must never abort the publication: the post already exists,
                // so a rethrow here would let retries create duplicate posts.

                // Link the pending row before the event fires so observers already see the marker.
                $DB->set_field('local_forum_ai_pending', 'postid', $postid, ['id' => $pending->id]);
                $pending->postid = $postid;

                $postrecord = $DB->get_record('forum_posts', ['id' => $postid], '*', MUST_EXIST);
                $event = \mod_forum\event\post_created::create([
                    'context' => \context_module::instance($cm->id),
                    'objectid' => $postid,
                    'other' => [
                        'discussionid' => $discussion->id,
                        'forumid' => $forum->id,
                        'forumtype' => $forum->type,
                    ],
                ]);
                $event->add_record_snapshot('forum_posts', $postrecord);
                $event->add_record_snapshot('forum_discussions', $discussion);
                $event->trigger();

                // Update the completion state for the author (still the current user here).
                $completion = new \completion_info($course);
                if ($completion->is_enabled($cm) && ($forum->completionreplies || $forum->completionposts)) {
                    $completion->update_state($cm, COMPLETION_COMPLETE);
                }
            } catch (\Exception $e) {
                debugging('Error in AI post publication follow-ups: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }

            return $postid;
        } finally {
            if ($originaluser !== null) {
                \core\cron::setup_user($originaluser);
            }
        }
    }

    /**
     * Rates a forum post on behalf of a user through Moodle's standard rating API.
     *
     * Rating is best effort: this method never throws and a false return must
     * NEVER block or undo the publication of the AI response.
     *
     * It delegates to the plugin's single shared writer,
     * local_forum_ai_add_rating(), which runs core's full validation chain
     * through rating_manager::add_rating() (forum_rating_validate: assessment
     * window, groups, scale, self-rating, post visibility), pushes the grade
     * to the gradebook, and switches to the rater when needed so the
     * \core\event\user_graded event is attributed to the grader.
     *
     * @param \stdClass $cm Course module record.
     * @param \context_module $context Module context.
     * @param \stdClass $forum Forum record.
     * @param int $postid ID of the post being rated.
     * @param int $rateduserid Author of the rated post.
     * @param int $grade Rating value.
     * @param int $rateruserid User the rating is attributed to.
     * @param string|null $failurereason Set to a short specific reason on every
     *                                   false return, so callers can surface it
     *                                   (debugging() alone is silent in production).
     * @return bool True when the rating was stored, false otherwise.
     */
    public static function rate_ai_post(
        \stdClass $cm,
        \context_module $context,
        \stdClass $forum,
        int $postid,
        int $rateduserid,
        int $grade,
        int $rateruserid,
        ?string &$failurereason = null
    ): bool {
        global $USER;

        // Reject a missing or inactive rater with a specific reason before the
        // shared writer collapses it into a generic error code.
        if ((int) $USER->id !== $rateruserid) {
            $rater = \core_user::get_user($rateruserid);
            if (!$rater || !empty($rater->deleted) || !empty($rater->suspended)) {
                $failurereason = 'rater missing or inactive';
                debugging(
                    'Cannot rate AI post: rater user ' . $rateruserid . ' is missing or inactive',
                    DEBUG_DEVELOPER
                );
                return false;
            }
        }

        try {
            // Parity with core's rating callers; mod/forum:rate is checked inside
            // add_rating() through the forum permissions callback.
            if (!has_capability('moodle/rating:rate', $context, $rateruserid)) {
                $failurereason = 'rater lacks moodle/rating:rate';
                debugging(
                    'Cannot rate AI post: rater user ' . $rateruserid . ' lacks moodle/rating:rate',
                    DEBUG_DEVELOPER
                );
                return false;
            }

            $result = local_forum_ai_add_rating(
                $cm,
                $context,
                'mod_forum',
                'post',
                $postid,
                (int) $forum->scale,
                $grade,
                $rateduserid,
                (int) $forum->assessed,
                $rateruserid
            );

            if (!empty($result->error)) {
                $failurereason = (string) $result->error;
                debugging('Cannot rate AI post: ' . $result->error, DEBUG_DEVELOPER);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $failurereason = ($e instanceof \moodle_exception) ? (string) $e->errorcode : $e->getMessage();
            debugging('Cannot rate AI post: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
}
