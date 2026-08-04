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

namespace local_forum_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once(__DIR__ . '/../../locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use moodle_exception;

/**
 * External service to approve or reject AI-generated responses in forums.
 *
 * Defines the webservice function `local_forum_ai_approve_response`
 * which allows approving or rejecting pending AI responses.
 *
 * @package    local_forum_ai
 * @category   external
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class approve_response extends external_api {
    /**
     * Define the input parameters of the external function.
     *
     * @return external_function_parameters Parameter structure
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'token'  => new external_value(PARAM_ALPHANUMEXT, 'Approval token'),
            'action' => new external_value(PARAM_ALPHA, 'Action: approve|reject'),
        ]);
    }

    /**
     * Perform the action of approving or rejecting a response.
     *
     * @param string $token  Approval token associated with the pending response
     * @param string $action Action to perform: approve or reject
     * @return array Result with 'success' key in case of success
     * @throws moodle_exception If the validations or permissions are not met
     */
    public static function execute($token, $action) {
        global $DB, $CFG, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'token'  => $token,
            'action' => $action,
        ]);

        $pending = $DB->get_record(
            'local_forum_ai_pending',
            ['approval_token' => $params['token'], 'status' => 'pending'],
            '*',
            MUST_EXIST
        );

        $discussion = $DB->get_record('forum_discussions', ['id' => $pending->discussionid], '*', MUST_EXIST);
        $forum      = $DB->get_record('forum', ['id' => $pending->forumid], '*', MUST_EXIST);
        $course     = $DB->get_record('course', ['id' => $forum->course], '*', MUST_EXIST);
        $cm         = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/forum:viewdiscussion', $context);

        if ($params['action'] === 'approve') {
            require_once($CFG->dirroot . '/mod/forum/lib.php');

            // Determine the correct parent post based on parentpostid.
            $parentid = $discussion->firstpost;

            if (!empty($pending->parentpostid)) {
                // Verify that parentpostid exists and belongs to this discussion.
                $parentpost = $DB->get_record('forum_posts', [
                    'id' => $pending->parentpostid,
                    'discussion' => $discussion->id,
                ]);

                if ($parentpost) {
                    $parentid = $pending->parentpostid;
                } else {
                    // If the parent post does not exist, log the error and use firstpost instead.
                    debugging(
                        'Parent post ID ' . $pending->parentpostid . ' not found, using firstpost instead',
                        DEBUG_DEVELOPER
                    );
                }
            }

            $post = new \stdClass();
            $post->discussion    = $discussion->id;
            $post->parent        = $parentid;
            // In manual mode, attribute the AI response to the user who approved it.
            $post->userid        = $USER->id;
            $post->created       = time();
            $post->modified      = time();
            $post->subject       = $pending->subject ?: ("Re: " . $discussion->name);
            $post->message       = $pending->message;
            $post->messageformat = FORMAT_HTML;
            $post->messagetrust  = 1;

            $newpostid = forum_add_new_post($post, null);

            $gradingenabled = ($forum->assessed != 0);

            if ($gradingenabled && !empty($pending->grade) && !empty($pending->parentpostid)) {
                try {
                    // In manual mode, attribute grading to the user who approved it.
                    $graderid = $USER->id;

                    // Get the original post that was graded.
                    $originalpost = $DB->get_record('forum_posts', ['id' => $pending->parentpostid]);

                    if ($originalpost) {
                        // Use custom function to add rating without modifying global $USER.
                        $result = local_forum_ai_add_rating(
                            $cm,
                            $context,
                            'mod_forum',
                            'post',
                            $pending->parentpostid,
                            $forum->scale,
                            $pending->grade,
                            $originalpost->userid,
                            $forum->assessed,
                            $graderid
                        );

                        if (!empty($result->error)) {
                            debugging('Error adding AI rating on manual approval: ' . $result->error, DEBUG_DEVELOPER);
                        }
                    }
                } catch (\Exception $e) {
                    debugging('Exception adding rating on manual approval: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }

            $pending->status       = 'approved';
            $pending->creator_userid = $USER->id;
            $pending->approved_at  = time();
            $pending->timemodified = time();
            $DB->update_record('local_forum_ai_pending', $pending);
        } else if ($params['action'] === 'reject') {
            $pending->status       = 'rejected';
            $pending->creator_userid = $USER->id;
            $pending->timemodified = time();
            $DB->update_record('local_forum_ai_pending', $pending);
        } else {
            throw new moodle_exception('invalidaction', 'local_forum_ai');
        }

        return ['success' => true];
    }

    /**
     * Define the output structure of the external function.
     *
     * @return external_single_structure Return structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'If the action was successful'),
        ]);
    }
}
