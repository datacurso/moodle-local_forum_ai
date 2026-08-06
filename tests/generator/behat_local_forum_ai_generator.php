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

/**
 * Behat data generator for local_forum_ai.
 *
 * @package    local_forum_ai
 * @category   test
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_forum_ai_generator extends behat_generator_base {

    /**
     * Get a list of the entities that Behat can create using the generator step.
     *
     * Usage in Gherkin:
     *   Given the following "local_forum_ai > pending responses" exist:
     *     | forum      | discussion   | user     | subject | message | status  |
     *     | Test forum | Discussion A | student1 | Re: A   | Hello   | pending |
     *
     *   Given the following "local_forum_ai > configs" exist:
     *     | forum      | enabled | require_approval |
     *     | Test forum | 1       | 1                |
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'pending responses' => [
                'singular' => 'pending response',
                'datagenerator' => 'pending_response',
                'required' => ['forum', 'discussion'],
                'switchids' => [
                    'forum' => 'forumid',
                    'discussion' => 'discussionid',
                    'user' => 'creator_userid',
                ],
            ],
            'configs' => [
                'singular' => 'config',
                'datagenerator' => 'config',
                'required' => ['forum'],
                'switchids' => [
                    'forum' => 'forumid',
                    'grader' => 'graderid',
                ],
            ],
        ];
    }

    /**
     * Get the forum id using an activity idnumber or name.
     *
     * @param string $idnumberorname The forum activity idnumber or name.
     * @return int The forum id.
     */
    protected function get_forum_id(string $idnumberorname): int {
        return $this->get_cm_by_activity_name('forum', $idnumberorname)->instance;
    }

    /**
     * Get the discussion id using the discussion name.
     *
     * @param string $name The discussion name.
     * @return int The discussion id.
     */
    protected function get_discussion_id(string $name): int {
        global $DB;

        if (!$id = $DB->get_field('forum_discussions', 'id', ['name' => $name])) {
            throw new Exception('The specified discussion with name "' . $name . '" could not be found.');
        }

        return (int) $id;
    }

    /**
     * Get the grader user id from a username.
     *
     * @param string $username The username.
     * @return int The user id.
     */
    protected function get_grader_id(string $username): int {
        return $this->get_user_id($username);
    }
}
