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

global $CFG;

require_once(__DIR__ . '/../../locallib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
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
     * @throws moodle_exception If the validations or permissions are not met, including
     *                          separate-groups access to the discussion, or when another
     *                          request is managing the response (error_responsebusy)
     */
    public static function execute($token, $action) {
        global $DB, $CFG, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'token'  => $token,
            'action' => $action,
        ]);

        // Token, capability and discussion group are enforced in one place.
        $loaded = \local_forum_ai\approval::load_pending_for_user($params['token'], 'pending');
        $pending = $loaded->pending;
        $discussion = $loaded->discussion;
        $forum = $loaded->forum;
        $course = $loaded->course;
        $cm = $loaded->cm;
        $context = $loaded->context;
        self::validate_context($context);

        $config = $DB->get_record('local_forum_ai_config', ['forumid' => $forum->id]) ?: null;

        // Restored or legacy rows may hold a published post while still marked pending:
        // an already-published response must never be re-published.
        if (!empty($pending->postid)) {
            throw new moodle_exception('error_responsenotpending', 'local_forum_ai');
        }

        if ($params['action'] === 'approve') {
            require_once($CFG->dirroot . '/mod/forum/lib.php');

            // Same deadline rule as the expiry cleanup: a response past it is never published.
            if (\local_forum_ai\utils::is_forum_deadline_reached($forum)) {
                local_forum_ai_expire_pending($pending);
                throw new moodle_exception('error_forumclosed', 'local_forum_ai');
            }

            if (!\local_forum_ai\utils::can_reply_in_discussion($forum, $discussion, $config)) {
                throw new moodle_exception('error_discussionlocked', 'local_forum_ai');
            }

            // The response is published on behalf of $USER: core's group rules for
            // replying apply (e.g. no replies to all-participants discussions in
            // separate groups without accessallgroups).
            if (!\local_forum_ai\utils::can_user_reply_in_discussion_group($cm, $course, $discussion, (int) $USER->id)) {
                throw new moodle_exception('error_cannotpublishingroup', 'local_forum_ai');
            }

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

                    // Core forbids replying to private replies (forum_add_new_post would throw
                    // a coding_exception): surface a clean, localized error instead. The
                    // firstpost fallback needs no check because a first post is never private.
                    if (\local_forum_ai\utils::is_private_reply($parentpost)) {
                        throw new moodle_exception('error_privatereply', 'local_forum_ai');
                    }
                } else {
                    // If the parent post does not exist, log the error and use firstpost instead.
                    debugging(
                        'Parent post ID ' . $pending->parentpostid . ' not found, using firstpost instead',
                        DEBUG_DEVELOPER
                    );
                }
            }

            // Publication and the state change run under the row lock, on the row re-read
            // there: a concurrent approve, reject or edit can never publish twice or revert it.
            $approverid = (int) $USER->id;
            $managed = \local_forum_ai\approval::transition_pending(
                (int) $pending->id,
                'approved',
                $approverid,
                function (\stdClass $current) use ($discussion, $forum, $cm, $course, $context, $parentid, $approverid): void {
                    global $DB;

                    // In manual mode the AI response is attributed to the user who approved it,
                    // so the shared publisher never needs to switch users on this path.
                    $newpostid = \local_forum_ai\approval::publish_ai_post(
                        $discussion,
                        $forum,
                        $cm,
                        $course,
                        $current,
                        (int) $parentid,
                        $approverid
                    );
                    if (!$newpostid) {
                        // Defense in depth: false only happens for pre-insert failures (the gates
                        // above should have caught them already), so no post was published.
                        throw new moodle_exception('error_privatereply', 'local_forum_ai');
                    }

                    $gradingenabled = ($forum->assessed != 0);

                    // Rating is best effort: a grade of zero is valid and must not be dropped.
                    if ($gradingenabled && $current->grade !== null && !empty($current->parentpostid)) {
                        $originalpost = $DB->get_record('forum_posts', ['id' => $current->parentpostid]);

                        if ($originalpost) {
                            // In manual mode, attribute the rating to the user who approved it.
                            $rated = \local_forum_ai\approval::rate_ai_post(
                                $cm,
                                $context,
                                $forum,
                                (int) $current->parentpostid,
                                (int) $originalpost->userid,
                                (int) $current->grade,
                                $approverid
                            );
                            if (!$rated) {
                                debugging(
                                    'AI rating skipped on manual approval for post ' . $current->parentpostid,
                                    DEBUG_DEVELOPER
                                );
                            }
                        }
                    }
                }
            );

            // Audit trail: record who approved the response in the standard log store.
            $event = \local_forum_ai\event\response_approved::create([
                'context' => $context,
                'objectid' => (int) $managed->id,
                'relateduserid' => (int) $managed->creator_userid,
                'other' => [
                    'forumid' => (int) $managed->forumid,
                    'discussionid' => (int) $managed->discussionid,
                ],
            ]);
            $event->trigger();
        } else if ($params['action'] === 'reject') {
            // Same exclusive, conditional transition as approval; nothing is published.
            $managed = \local_forum_ai\approval::transition_pending((int) $pending->id, 'rejected', (int) $USER->id);

            // Audit trail: a rejection leaves no post behind, so the event is its only trace.
            $event = \local_forum_ai\event\response_rejected::create([
                'context' => $context,
                'objectid' => (int) $managed->id,
                'relateduserid' => (int) $managed->creator_userid,
                'other' => [
                    'forumid' => (int) $managed->forumid,
                    'discussionid' => (int) $managed->discussionid,
                ],
            ]);
            $event->trigger();
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
