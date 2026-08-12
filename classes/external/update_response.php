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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use context_module;
use moodle_exception;

/**
 * External service to update a pending AI response in a forum.
 *
 * Defines the webservice function `local_forum_ai_update_response`
 * that allows modifying the message of an AI response before its approval.
 *
 * @package    local_forum_ai
 * @category   external
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_response extends external_api {
    /**
     * Defines the input parameters of the webservice function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'token'   => new external_value(PARAM_ALPHANUMEXT, 'Approval token'),
            // PARAM_RAW on purpose: external_api::validate_parameters() rejects (not cleans)
            // values that change under PARAM_CLEANHTML; dirty input must be neutralized instead.
            'message' => new external_value(PARAM_RAW, 'New AI message'),
        ]);
    }

    /**
     * Executes the update of a pending AI message.
     *
     * @param string $token Approval token
     * @param string $message New AI message
     * @return array Result with status and the updated message rendered as display-ready HTML
     * @throws \required_capability_exception If the caller does not hold local/forum_ai:approveresponses.
     * @throws \moodle_exception If the response is no longer pending.
     */
    public static function execute($token, $message) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'token' => $token,
            'message' => $message,
        ]);

        $pending = $DB->get_record('local_forum_ai_pending', ['approval_token' => $params['token']], '*', MUST_EXIST);

        // Resolve forum context from the pending record.
        $discussion = $DB->get_record('forum_discussions', ['id' => $pending->discussionid], '*', MUST_EXIST);
        $forum = $DB->get_record('forum', ['id' => $pending->forumid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $forum->course], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/forum_ai:approveresponses', $context);

        // Only pending responses may be edited; approved or rejected history records are immutable.
        if ($pending->status !== 'pending') {
            throw new moodle_exception('error_responsenotpending', 'local_forum_ai');
        }

        // Edited AI responses remain external, untrusted content: purify before storing.
        $pending->message = clean_text($params['message'], FORMAT_HTML);
        $pending->timemodified = time();
        $DB->update_record('local_forum_ai_pending', $pending);

        return [
            'status'  => 'ok',
            // Return display-ready HTML (same contract as get_details airesponse); the stored
            // value stays the pure clean_text() source so no-op round-trips remain byte-identical.
            'message' => format_text($pending->message, FORMAT_HTML),
        ];
    }

    /**
     * Defines the return structure of the webservice function.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'Operation status'),
            // PARAM_RAW: carries server-formatted safe HTML (format_text over the purified
            // stored source), the same display contract as get_details airesponse.
            'message' => new external_value(PARAM_RAW, 'Updated message as server-formatted safe HTML'),
        ]);
    }
}
