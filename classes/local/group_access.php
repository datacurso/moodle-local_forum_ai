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

namespace local_forum_ai\local;

/**
 * Single predicate for "may this user access this forum discussion?" under group mode.
 *
 * Mirrors the view rules of mod_forum in separate groups mode: users holding
 * moodle/site:accessallgroups see every discussion; everyone else only sees
 * discussions for all participants (groupid -1) and those of the groups
 * returned by groups_get_activity_allowed_groups(), which honours groupings.
 * Without separate groups every discussion is accessible.
 *
 * @package    local_forum_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_access {
    /**
     * Returns the discussion group ids the user may access, for SQL filters.
     *
     * @param \cm_info|\stdClass $cm Forum course module.
     * @param \stdClass $course Course record.
     * @param int $userid User to evaluate.
     * @return int[]|null Null when no group restriction applies; otherwise the
     *                    allowed group ids plus -1 (all participants).
     */
    public static function get_allowed_discussion_groupids($cm, \stdClass $course, int $userid): ?array {
        if (groups_get_activity_groupmode($cm, $course) != SEPARATEGROUPS) {
            return null;
        }

        if (has_capability('moodle/site:accessallgroups', \context_module::instance($cm->id), $userid)) {
            return null;
        }

        $groupids = array_map('intval', array_keys(groups_get_activity_allowed_groups($cm, $userid)));
        $groupids[] = -1;

        return $groupids;
    }

    /**
     * Whether the user may access a discussion of the given group.
     *
     * @param \cm_info|\stdClass $cm Forum course module.
     * @param \stdClass $course Course record.
     * @param int $discussiongroupid Discussion group id (-1 for all participants).
     * @param int $userid User to evaluate.
     * @return bool
     */
    public static function can_access_discussion($cm, \stdClass $course, int $discussiongroupid, int $userid): bool {
        $allowed = self::get_allowed_discussion_groupids($cm, $course, $userid);

        return $allowed === null || in_array($discussiongroupid, $allowed, true);
    }

    /**
     * Throws when the user may not access a discussion of the given group.
     *
     * @param \cm_info|\stdClass $cm Forum course module.
     * @param \stdClass $course Course record.
     * @param int $discussiongroupid Discussion group id (-1 for all participants).
     * @param int $userid User to evaluate.
     * @return void
     * @throws \moodle_exception error_discussionnotingroup when access is denied.
     */
    public static function require_discussion_access($cm, \stdClass $course, int $discussiongroupid, int $userid): void {
        if (!self::can_access_discussion($cm, $course, $discussiongroupid, $userid)) {
            throw new \moodle_exception('error_discussionnotingroup', 'local_forum_ai');
        }
    }
}
