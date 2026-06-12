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

/**
 * Class for role checking functionality.
 *
 * @package    local_forum_ai
 * @category   event
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class role_checker {
    /**
     * Checks if the given user has any of the allowed roles in the module context.
     *
     * @param int $forumid
     * @param int $userid
     * @param array|string $allowedroles Array of role ids (as integers) OR CSV string.
     *        An empty value means no role restriction: every user is allowed.
     * @return bool True if user has at least one allowed role; false otherwise.
     */
    public static function user_has_allowed_role(int $forumid, int $userid, $allowedroles): bool {
        global $DB;

        if (is_string($allowedroles)) {
            $allowedroles = $allowedroles === '' ? [] : explode(',', $allowedroles);
        }

        // No roles configured means no restriction. Previously this returned false,
        // which silently disabled AI replies for every post in the forum.
        if (empty($allowedroles)) {
            return true;
        }

        $forum = $DB->get_record('forum', ['id' => $forumid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $forum->course, false, MUST_EXIST);

        $contextmodule = \context_module::instance($cm->id);
        $contextcourse = \context_course::instance($forum->course);
        $contextcat = $contextcourse->get_parent_context();
        $contextsystem = \context_system::instance();
        $contexts = [$contextmodule, $contextcourse, $contextcat, $contextsystem];

        foreach ($contexts as $context) {
            $userroles = get_user_roles($context, $userid);
            foreach ($userroles as $ur) {
                if (in_array((string)$ur->roleid, $allowedroles, true) || in_array((int)$ur->roleid, $allowedroles, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
