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

namespace local_forum_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use local_forum_ai\ai_service;
use local_forum_ai\utils;

/**
 * External function to process AI-based review of forum activity.
 *
 * This function sends forum and user data to the AI service in order to
 * obtain an automatic evaluation, which may be returned as a simple grade,
 * a rubric structure, or an evaluation guide.
 *
 * @package     local_forum_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_review extends external_api {
    /**
     * Returns the description of the parameters for this external function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'userid' => new external_value(PARAM_INT, 'User ID'),
        ]);
    }

    /**
     * Executes the external function.
     *
     * Builds the payload with the required forum and user information,
     * sends it to the AI service and processes the response according
     * to the returned evaluation type.
     *
     * Possible response types:
     * - simple: direct numeric grade.
     * - rubric: detailed rubric structure.
     * - guide: evaluation guide with multiple criteria.
     *
     * @param int $cmid   Course module ID.
     * @param int $userid User ID to be evaluated.
     * @return array Structured result containing evaluation type and serialized data.
     * @throws \moodle_exception If the AI response format is not recognized.
     */
    public static function execute($cmid, $userid) {

        self::validate_parameters(self::execute_parameters(), compact('cmid', 'userid'));

        $payload = utils::build_forum_ai_payload($cmid, $userid);

        $response = ai_service::call_ai_service_global($payload);

        // Simple grade.
        if (isset($response['grade']) && is_numeric($response['grade'])) {
            return [
                'type' => 'simple',
                'data' => json_encode([
                    'grade' => (float) $response['grade'],
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        // Rubric.
        if (isset($response['rubric'])) {
            return [
                'type' => 'rubric',
                'data' => json_encode($response['rubric'], JSON_UNESCAPED_UNICODE),
            ];
        }

        // Evaluation guide.
        if (is_array($response)) {
            return [
                'type' => 'guide',
                'data' => json_encode($response, JSON_UNESCAPED_UNICODE),
            ];
        }

        throw new \moodle_exception('Unrecognized AI response format');
    }

    /**
     * Returns the description of the return values.
     *
     * Defines the exact structure returned by this external service,
     * specifying the evaluation type and the serialized data
     * provided by the AI service.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'type' => new external_value(PARAM_ALPHA, 'Evaluation type'),
            'data' => new external_value(PARAM_RAW, 'Serialized AI Response'),
        ]);
    }
}
