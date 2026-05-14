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
 * Ad-hoc task to process AI responses for forum discussions.
 *
 * This task is queued when a new discussion is created in a forum with
 * AI enabled and configured to respond to initial conversations.
 *
 * @package    local_forum_ai
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_ai_discussion extends adhoc_task {
    /**
     * Executes the queued ad-hoc task.
     *
     * Retrieves the discussion data, calls the AI service to generate a response
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
        $discussionid = $data->discussionid;

        try {
            $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid], '*', MUST_EXIST);
            $forum = $DB->get_record('forum', ['id' => $discussion->forum], '*', MUST_EXIST);
            $course = $DB->get_record('course', ['id' => $forum->course], '*', MUST_EXIST);
            $post = $DB->get_record('forum_posts', ['id' => $discussion->firstpost], '*', MUST_EXIST);

            $config = $DB->get_record('local_forum_ai_config', ['forumid' => $forum->id]) ?: new \stdClass();
            $enabled = $config->enabled ?? get_config('local_forum_ai', 'default_enabled');
            $replymessage = $config->reply_message ?? get_config('local_forum_ai', 'default_reply_message');
            $requireapproval = $config->require_approval ?? 1;
            $enablediainitconversation = $config->enablediainitconversation ?? 0;
            $allowedroles = $config->allowedroles ?? '';
            $graderid = $config->graderid ?? null;
            $effectivegraderid = !$requireapproval ? $graderid : null;
            $questionturnslimit = utils::get_effective_question_turns($config);
            $allowfollowupquestion = utils::should_allow_followup_question(
                (int)$discussionid,
                (int)$post->id,
                $questionturnslimit
            );

            if (!$requireapproval && !$effectivegraderid) {
                debugging('Automatic approval requires a configured grader in forum ' . $forum->id, DEBUG_DEVELOPER);
                $requireapproval = 1;
            }

            if (!$enabled || empty($enablediainitconversation)) {
                return;
            }

            if (!role_checker::user_has_allowed_role($forum->id, $discussion->userid, $allowedroles)) {
                return;
            }

            $gradingenabled = ($forum->assessed != 0);

            $payload = [
                'course' => $course->fullname,
                'forum' => $forum->name,
                'discussion' => $discussion->name,
                'discussion_id' => $discussionid,
                // Only consider configured auto-grader in automatic approval mode.
                'userid' => (string)($effectivegraderid ?? 2),
                'postid' => $post->id,
                'prompt' => $replymessage,
                'allow_followup_question' => $allowfollowupquestion,
                'grading_enabled' => $gradingenabled,
                'scale' => $gradingenabled ? $forum->scale : null,
            ];

            $airesponse = ai_service::call_ai_service($payload);
            $replytext = $airesponse['reply'] ?? '';
            $grade = $gradingenabled ? ($airesponse['grade'] ?? null) : null;

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
                        $discussion->firstpost,
                        $forum->scale,
                        $grade,
                        $discussion->userid,
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

            approval::create_approval_request(
                $discussion,
                $forum,
                $replytext,
                $requireapproval ? 'pending' : 'approved',
                $discussion->firstpost,
                $grade,
                (!$requireapproval && $effectivegraderid) ? $effectivegraderid : $discussion->userid
            );

            if (!$requireapproval) {
                approval::create_ai_reply($discussion, $replytext, $discussion->firstpost, $effectivegraderid);
            }
        } catch (\Throwable $e) {
            debugging('Error in process_ai_discussion task: ' . $e->getMessage(), DEBUG_DEVELOPER);
            throw $e;
        }
    }
}
