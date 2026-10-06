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
use local_forum_ai\approval;
use local_forum_ai\local\editable_text;
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
            'plaintext' => new external_value(
                PARAM_BOOL,
                'Whether message is plain text from the edit box (converted to escaped paragraphs)',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Executes the update of a pending AI message.
     *
     * Stores the purified message and triggers a response_updated audit event.
     * With $plaintext the message is plain text: unchanged text keeps the stored
     * HTML, any other text is stored as escaped, purified paragraphs.
     *
     * @param string $token Approval token
     * @param string $message New AI message
     * @param bool $plaintext Whether $message is plain text from the edit box
     * @return array Result with status and the updated message rendered as display-ready HTML
     * @throws \required_capability_exception If the caller does not hold local/forum_ai:approveresponses.
     * @throws \moodle_exception If the response is no longer pending, another request is
     *                           managing it, or the discussion belongs to a group the
     *                           caller cannot access.
     */
    public static function execute($token, $message, $plaintext = false) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'token' => $token,
            'message' => $message,
            'plaintext' => $plaintext,
        ]);

        // Token, capability and discussion group are enforced in one place.
        $loaded = approval::load_pending_for_user($params['token']);
        $context = $loaded->context;
        self::validate_context($context);

        // The edit shares the approval lock and only writes while the row is still pending,
        // so it can never revert a concurrent approval (status and postid are never written).
        $compose = static function (string $stored) use ($params): string {
            // Edited AI responses remain external, untrusted content: purify before storing.
            if (!$params['plaintext']) {
                return clean_text($params['message'], FORMAT_HTML);
            } else if (editable_text::is_unchanged($params['message'], $stored)) {
                // Unchanged text keeps the stored formatting (bold, lists, links), still purified.
                return clean_text($stored, FORMAT_HTML);
            }
            // Typed text is escaped, so markup written by the teacher is stored as visible text.
            return editable_text::to_html($params['message']);
        };
        $pending = approval::edit_pending_message((int) $loaded->pending->id, $compose);

        // Audit trail: edits of a pending response must be traceable in the standard log store.
        $event = \local_forum_ai\event\response_updated::create([
            'context' => $context,
            'objectid' => (int) $pending->id,
            'relateduserid' => (int) $pending->creator_userid,
            'other' => [
                'forumid' => (int) $pending->forumid,
                'discussionid' => (int) $pending->discussionid,
            ],
        ]);
        $event->trigger();

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
