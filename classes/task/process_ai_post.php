<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_forum_ai\task;

use core\task\adhoc_task;
use local_forum_ai\ai_service;
use local_forum_ai\approval;
use local_forum_ai\role_checker;
use local_forum_ai\utils;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../locallib.php');

/**
 * Ad-hoc task to process AI responses and grading for forum posts.
 *
 * This task is queued when a new post is created in a forum with AI enabled.
 * It generates an AI response and optionally applies a rating based on the
 * forum configuration.
 *
 * @package    local_forum_ai
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_ai_post extends adhoc_task {
    /**
     * Executes the queued ad-hoc task.
     *
     * Retrieves the post data, calls the AI service to generate a response
     * and grade (if applicable), and either posts the response immediately
     * or creates a pending approval request.
     *
     * @return void
     * @throws \dml_exception
     */
    public function execute() {
        global $DB, $CFG;

        if (!utils::is_feature_enabled()) {
            return;
        }

        if (!utils::is_global_ai_enabled()) {
            return;
        }

        // Get custom data passed to the task.
        $data = $this->get_custom_data();
        $postid = $data->postid;

        try {
            $post = $DB->get_record('forum_posts', ['id' => $postid], '*', MUST_EXIST);
            $discussion = $DB->get_record('forum_discussions', ['id' => $post->discussion], '*', MUST_EXIST);
            $forum = $DB->get_record('forum', ['id' => $discussion->forum], '*', MUST_EXIST);
            $course = $DB->get_record('course', ['id' => $forum->course], '*', MUST_EXIST);

            // Skip processing if this is the first post of a discussion.
            if ($post->id == $discussion->firstpost) {
                return;
            }

            $config = $DB->get_record('local_forum_ai_config', ['forumid' => $forum->id]) ?: new \stdClass();
            $enabled = $config->enabled ?? get_config('local_forum_ai', 'default_enabled');
            $replymessage = $config->reply_message ?? get_config('local_forum_ai', 'default_reply_message');
            $requireapproval = $config->require_approval ?? 1;
            $allowedroles = $config->allowedroles ?? '';
            $graderid = $config->graderid ?? null;
            $effectivegraderid = !$requireapproval ? $graderid : null;
            $questionturnslimit = utils::get_effective_question_turns($config);
            $allowfollowupquestion = utils::should_allow_followup_question(
                (int)$discussion->id,
                (int)$post->id,
                $questionturnslimit
            );

            if (!$requireapproval && !$effectivegraderid) {
                debugging('Automatic approval requires a configured grader in forum ' . $forum->id, DEBUG_DEVELOPER);
                $requireapproval = 1;
            }

            if (!$enabled) {
                mtrace("local_forum_ai: skipping post {$post->id} — AI disabled for forum {$forum->id}.");
                return;
            }

            if (utils::is_forum_cutoff_reached($forum)) {
                mtrace("local_forum_ai: skipping post {$post->id} — forum {$forum->id} cut-off date has passed.");
                return;
            }

            if (!utils::can_reply_in_discussion($forum, $discussion, $config)) {
                mtrace("local_forum_ai: skipping post {$post->id} — discussion {$discussion->id} is locked.");
                return;
            }

            if (utils::is_private_reply($post)) {
                mtrace("local_forum_ai: skipping post {$post->id} — it is a private reply.");
                return;
            }

            // Never reply to posts authored by the configured AI grader (avoid self-replies).
            if (!empty($config->graderid) && (int)$post->userid === (int)$config->graderid) {
                mtrace("local_forum_ai: skipping post {$post->id} — authored by the AI grader user.");
                return;
            }

            if (!role_checker::user_has_allowed_role($forum->id, $post->userid, $allowedroles)) {
                mtrace("local_forum_ai: skipping post {$post->id} — author {$post->userid} has no allowed role " .
                    "(forum {$forum->id}, allowedroles='{$allowedroles}').");
                return;
            }

            $gradingenabled = ($forum->assessed != 0);

            $postmessage = format_text($post->message, $post->messageformat, [
                'context' => \context_module::instance($data->cmid),
            ]);
            $postmessage = strip_tags($postmessage);
            $postmessage = trim($postmessage);

            $postauthor = \core_user::get_user($post->userid);
            $postauthorname = $postauthor ? fullname($postauthor) : '';

            $payload = [
                'course' => $course->fullname,
                'forum' => $forum->name,
                'discussion' => $discussion->name,
                'discussion_id' => $discussion->id,
                'postid' => $post->id,
                'post' => [
                    'subject' => $post->subject,
                    'message' => $postmessage,
                    // Display name of the post author so the AI never greets by numeric id.
                    'author' => $postauthorname,
                ],
                // Thread context sent inline — no MCP needed.
                'thread_history' => utils::build_thread_context(
                    (int)$discussion->id,
                    (int)$post->id,
                ),
                // Attribute the request to the post author (rate limits are per user).
                'userid' => (string)$post->userid,
                'prompt' => $replymessage,
                'allow_followup_question' => $allowfollowupquestion,
                'grading_enabled' => $gradingenabled,
                'scale' => $gradingenabled ? utils::get_scale_payload((int)$forum->scale) : null,
            ];

            $airesponse = ai_service::call_ai_service($payload);
            $replytext = $airesponse['reply'] ?? '';
            $grade = $gradingenabled ? ($airesponse['grade'] ?? null) : null;

            if ($gradingenabled && $grade === null) {
                mtrace("local_forum_ai: AI response for post {$post->id} contained no grade; " .
                    'no rating will be applied.');
            }

            if (!$requireapproval && $gradingenabled && $grade !== null && $effectivegraderid) {
                $context = \context_module::instance($data->cmid);
                $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

                try {
                    // Use custom function to add rating without modifying global $USER.
                    $result = local_forum_ai_add_rating(
                        $cm,
                        $context,
                        'mod_forum',
                        'post',
                        $post->id,
                        $forum->scale,
                        $grade,
                        $post->userid,
                        $forum->assessed,
                        $effectivegraderid
                    );

                    if (!empty($result->error)) {
                        debugging('Error adding AI rating: ' . $result->error, DEBUG_DEVELOPER);
                    }
                } catch (\Exception $e) {
                    debugging('Exception adding AI rating: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            } else if (!$requireapproval && $gradingenabled && $grade !== null && !$effectivegraderid) {
                debugging('Grading enabled but no grader configured for forum ' . $forum->id, DEBUG_DEVELOPER);
            }

            $pendingid = approval::create_approval_request(
                $discussion,
                $forum,
                $replytext,
                $requireapproval ? 'pending' : 'approved',
                $post->id,
                $grade,
                (!$requireapproval && $effectivegraderid) ? $effectivegraderid : $post->userid
            );

            if (!$requireapproval && $pendingid) {
                $pendingrow = $DB->get_record('local_forum_ai_pending', ['id' => $pendingid], '*', MUST_EXIST);
                $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);
                $published = approval::publish_ai_post(
                    $discussion,
                    $forum,
                    $cm,
                    $course,
                    $pendingrow,
                    (int) $post->id,
                    (int) $effectivegraderid
                );
                if (!$published) {
                    // A false return is final (inactive author or private parent): do not rethrow,
                    // an adhoc retry would only re-call the paid AI service for the same outcome.
                    mtrace("local_forum_ai: could not publish AI reply for pending {$pendingid}.");
                    return;
                }

                // Rating is best effort and accompanies the published response.
                if ($gradingenabled && $grade !== null && $effectivegraderid) {
                    $failurereason = null;
                    $rated = approval::rate_ai_post(
                        $cm,
                        \context_module::instance($data->cmid),
                        $forum,
                        (int) $post->id,
                        (int) $post->userid,
                        (int) $grade,
                        (int) $effectivegraderid,
                        $failurereason
                    );
                    if (!$rated) {
                        mtrace("local_forum_ai: rating skipped for post {$post->id} — {$failurereason}.");
                    }
                }
            }
        } catch (\Throwable $e) {
            debugging('Error in process_ai_post task: ' . $e->getMessage(), DEBUG_DEVELOPER);
            throw $e;
        }
    }
}
