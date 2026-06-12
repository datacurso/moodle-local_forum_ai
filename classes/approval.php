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
     * Creates an approval request and sends a notification.
     *
     * @param object $discussion The discussion object.
     * @param object $forum The forum object.
     * @param string $message The AI-generated message.
     * @param string $status The approval status ('pending' or 'approved'). Defaults to 'pending'.
     * @param int|null $parentpostid The ID of the parent post to reply to, or null if top-level.
     * @param int|null $grade AI-generated grade, if applicable.
     * @param int|null $creatoruserid User ID to attribute as creator in pending/history.
     * @return void
     */
    public static function create_approval_request(
        $discussion,
        $forum,
        string $message,
        string $status = 'pending',
        ?int $parentpostid = null,
        ?int $grade = null,
        ?int $creatoruserid = null
    ): void {
        global $DB;

        try {
            $approvaltoken = hash('sha256', $discussion->id . time() . random_string(20));

            $pending = new \stdClass();
            $pending->discussionid = $discussion->id;
            $pending->forumid = $forum->id;
            $pending->creator_userid = $creatoruserid ?? $discussion->userid;
            $pending->subject = "Re: " . $discussion->name;
            $pending->message = $message;
            $pending->status = $status;
            $pending->approval_token = $approvaltoken;
            $pending->parentpostid = $parentpostid;
            $pending->timecreated = time();

            if ($forum->assessed != 0 && $grade !== null) {
                $pending->grade = $grade;
            }

            $pendingid = $DB->insert_record('local_forum_ai_pending', $pending);

            if ($status === 'pending') {
                self::send_moodle_notification($discussion, $forum, $pendingid, $approvaltoken);
            }
        } catch (\Exception $e) {
            debugging('Error in create_approval_request: ' . $e->getMessage(), DEBUG_DEVELOPER);
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
            $recipients = get_users_by_capability($context, 'mod/forum:replypost');

            $allowedroles = ['manager', 'editingteacher', 'coursecreator'];
            $finalrecipients = [];

            foreach ($recipients as $recipient) {
                $roles = get_user_roles($context, $recipient->id);
                foreach ($roles as $role) {
                    if (in_array($role->shortname, $allowedroles)) {
                        $finalrecipients[$recipient->id] = $recipient;
                    }
                }
            }

            if (empty($finalrecipients)) {
                return false;
            }

            $reviewurl = new \moodle_url('/local/forum_ai/review.php', ['token' => $approvaltoken]);
            $approveurl = new \moodle_url('/local/forum_ai/approve.php', ['token' => $approvaltoken, 'action' => 'approve']);
            $rejecturl = new \moodle_url('/local/forum_ai/approve.php', ['token' => $approvaltoken, 'action' => 'reject']);

            $renderer = null;
            try {
                $renderer = $PAGE->get_renderer('local_forum_ai');
            } catch (\Throwable $e) {
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
                    'approveurl' => $approveurl->out(false),
                    'rejecturl' => $rejecturl->out(false),
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
        } catch (\Throwable $e) {
            debugging('Error sending Moodle notification: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Creates an AI reply in the forum discussion.
     *
     * @param object $discussion The discussion object.
     * @param string $message The AI-generated message content.
     * @param int $parentpostid The ID of the parent post to reply to.
     * @param int $authoruserid User ID to use as reply author.
     * @return bool True on success, false on failure.
     */
    public static function create_ai_reply($discussion, string $message, int $parentpostid, int $authoruserid): bool {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/forum/lib.php');

        try {
            $author = $DB->get_record('user', ['id' => $authoruserid]);

            if (!$author) {
                debugging('Configured author user not found for AI reply: ' . $authoruserid, DEBUG_DEVELOPER);
                return false;
            }

            // Verify that the parentpostid exists and belongs to this discussion.
            $parentpost = $DB->get_record('forum_posts', [
                'id' => $parentpostid,
                'discussion' => $discussion->id,
            ]);

            if (!$parentpost) {
                debugging(
                    'Parent post ID ' . $parentpostid . ' not found or does not belong to discussion ' . $discussion->id,
                    DEBUG_DEVELOPER
                );
                // Use firstpost as fallback.
                $parentpostid = $discussion->firstpost;
            }

            $post = new \stdClass();
            $post->discussion = $discussion->id;
            $post->parent = $parentpostid;
            $post->userid = $author->id;
            $post->created = time();
            $post->modified = time();
            $post->subject = "Re: " . $discussion->name;
            $post->message = $message;
            $post->messageformat = FORMAT_HTML;
            $post->messagetrust = 1;
            $post->mailed = FORUM_MAILED_PENDING;
            $post->attachment = '';
            $post->totalscore = 0;
            $post->mailnow = 0;

            \mod_forum\local\entities\post::add_message_counts($post);

            $DB->insert_record('forum_posts', $post);

            // Bump the discussion so the AI reply shows up in discussion listings,
            // tracking and digests (a raw insert alone leaves it stale).
            $DB->set_field('forum_discussions', 'timemodified', $post->modified, ['id' => $discussion->id]);
            $DB->set_field('forum_discussions', 'usermodified', $post->userid, ['id' => $discussion->id]);

            return true;
        } catch (\Exception $e) {
            debugging('Error in create_ai_reply: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
}
