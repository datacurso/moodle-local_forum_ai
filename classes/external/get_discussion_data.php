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
use core_external\external_multiple_structure;
use local_forum_ai\approval;
use local_forum_ai\utils;

/**
 * External service to approve or reject AI-generated responses in forums.
 *
 * Define the webservice function `local_forum_ai_approve response`
 * which allows you to approve or reject pending responses.
 *
 * @package    local_forum_ai
 * @category   external
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_discussion_data extends external_api {
    /**
     * Define parameters for the function.
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'token' => new external_value(PARAM_ALPHANUMEXT, 'Approval token', VALUE_REQUIRED),
        ]);
    }

    /**
     * Executes the web service logic to retrieve discussion and AI response data.
     *
     * @param string $token The approval token used to identify the pending AI response.
     * @return array An array containing discussion, forum, course, AI response, and posts data.
     * @throws \invalid_parameter_exception If parameters are invalid.
     * @throws \required_capability_exception If the user lacks permission to view the discussion.
     * @throws \dml_missing_record_exception If a required record is not found.
     * @throws \moodle_exception If the discussion belongs to a group the caller cannot access.
     */
    public static function execute($token) {
        global $DB, $USER;

        // Parameter validation.
        $params = self::validate_parameters(self::execute_parameters(), ['token' => $token]);

        // Token, capability and discussion group are enforced in one place.
        $loaded = approval::load_pending_for_user($params['token']);
        $pending = $loaded->pending;
        $discussion = $loaded->discussion;
        $forum = $loaded->forum;
        $course = $loaded->course;
        self::validate_context($loaded->context);

        // Retrieve posts.
        $posts = utils::get_visible_discussion_posts((int) $discussion->id);

        $data = [
            'discussion' => format_string($discussion->name),
            'forum'      => format_string($forum->name),
            'course'     => format_string($course->fullname),
            'posts'      => [],
            'airesponse' => format_text($pending->message, FORMAT_HTML),
        ];

        foreach ($posts as $post) {
            $user = $DB->get_record('user', ['id' => $post->userid], 'id,firstname,lastname');
            $data['posts'][] = [
                'subject' => format_string($post->subject),
                'message' => format_text($post->message, $post->messageformat),
                'author'  => fullname($user),
                'created' => userdate($post->created),
            ];
        }

        return $data;
    }

    /**
     * Define the structure of the returned data.
     */
    public static function execute_returns() {
        return new external_single_structure([
            'discussion' => new external_value(PARAM_TEXT, 'Discussion name'),
            'forum'      => new external_value(PARAM_TEXT, 'Forum name'),
            'course'     => new external_value(PARAM_TEXT, 'Course name'),
            'airesponse' => new external_value(PARAM_RAW, 'AI-generated message'),
            'posts' => new external_multiple_structure(
                new external_single_structure([
                    'subject' => new external_value(PARAM_TEXT, 'Post subject'),
                    'message' => new external_value(PARAM_RAW, 'Post content'),
                    'author'  => new external_value(PARAM_TEXT, 'Post author'),
                    'created' => new external_value(PARAM_TEXT, 'Creation date'),
                ])
            ),
        ]);
    }
}
