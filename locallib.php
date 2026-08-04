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

/**
 * Helper functions for the Forum AI plugin.
 *
 * @package    local_forum_ai
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Kept for consistency with every sibling lib file, although the sniff deems
// it unneeded for a functions-only file.
defined('MOODLE_INTERNAL') || die(); // phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalNotNeeded

/**
 * Gets the list of pending responses.
 *
 * @package local_forum_ai
 * @param int $courseid Course ID.
 * @param int $forumid (optional) Forum ID to filter.
 * @return array list of objects with pending data.
 */
function local_forum_ai_get_pending(int $courseid, int $forumid = 0) {
    global $DB;

    $sql = "SELECT p.*, d.name AS discussionname, f.name AS forumname,
                   c.fullname AS coursename, u.firstname, u.lastname,
                   fp.subject AS discussionsubject, fp.message AS discussionmessage, fp.messageformat
              FROM {local_forum_ai_pending} p
              JOIN {forum_discussions} d ON d.id = p.discussionid
              JOIN {forum} f ON f.id = p.forumid
              JOIN {course} c ON c.id = f.course
              JOIN {course_modules} cm ON cm.instance = f.id AND cm.module = (
                    SELECT id FROM {modules} WHERE name = 'forum'
              )
              JOIN {user} u ON u.id = p.creator_userid
              JOIN {forum_posts} fp ON fp.id = d.firstpost
             WHERE p.status = :status
               AND f.course = :courseid
               AND cm.deletioninprogress = 0
               AND cm.visible = 1";

    $params = [
        'status' => 'pending',
        'courseid' => $courseid,
    ];

    if ($forumid > 0) {
        $sql .= " AND f.id = :forumid";
        $params['forumid'] = $forumid;
    }

    $sql .= " ORDER BY p.timecreated DESC";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Gets the list of response history.
 *
 * @package local_forum_ai
 * @param int $courseid Course ID.
 * @param int $forumid (optional) Forum ID to filter.
 * @return array list of response objects.
 */
function local_forum_ai_get_history(int $courseid, int $forumid = 0) {
    global $DB;

    $sql = "SELECT p.*, d.name AS discussionname, f.name AS forumname, c.fullname AS coursename,
                   u.firstname, u.lastname
              FROM {local_forum_ai_pending} p
              JOIN {forum_discussions} d ON d.id = p.discussionid
              JOIN {forum} f ON f.id = p.forumid
              JOIN {course} c ON c.id = f.course
              JOIN {course_modules} cm ON cm.instance = f.id AND cm.module = (
                    SELECT id FROM {modules} WHERE name = 'forum'
              )
              JOIN {user} u ON u.id = p.creator_userid
             WHERE p.status IN ('approved', 'rejected', 'expired')
               AND f.course = :courseid
               AND cm.deletioninprogress = 0
               AND cm.visible = 1";

    $params = ['courseid' => $courseid];

    if ($forumid > 0) {
        $sql .= " AND f.id = :forumid";
        $params['forumid'] = $forumid;
    }

    $sql .= " ORDER BY p.timecreated DESC";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Marks pending AI responses of expired forums as expired.
 *
 * Scoped to one course (and optionally one forum): opening the pending list
 * of a course must never touch other courses. Rows are kept for traceability
 * with status 'expired' instead of being deleted, so they reach the history.
 * Expiry criterion (unchanged): the forum cut-off date has passed, or the due
 * date has passed when no cut-off date is set.
 *
 * @package local_forum_ai
 * @param int $courseid Course ID the cleanup is scoped to.
 * @param int $forumid (optional) Forum ID to further scope the cleanup.
 * @return int Number of records marked as expired.
 */
function local_forum_ai_cleanup_expired(int $courseid, int $forumid = 0): int {
    global $DB;

    $now = time();

    $sql = "SELECT p.id
              FROM {local_forum_ai_pending} p
              JOIN {forum} f ON f.id = p.forumid
             WHERE p.status = 'pending'
               AND f.course = :courseid
               AND (
                   (f.cutoffdate > 0 AND f.cutoffdate < :now1)
                   OR (f.cutoffdate = 0 AND f.duedate > 0 AND f.duedate < :now2)
               )";

    $params = [
        'courseid' => $courseid,
        'now1' => $now,
        'now2' => $now,
    ];

    if ($forumid > 0) {
        $sql .= " AND f.id = :forumid";
        $params['forumid'] = $forumid;
    }

    $pendings = $DB->get_records_sql($sql, $params);

    if ($pendings) {
        $ids = array_keys($pendings);
        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $DB->set_field_select(
            'local_forum_ai_pending',
            'status',
            'expired',
            "id $insql",
            $inparams
        );
        $DB->set_field_select(
            'local_forum_ai_pending',
            'timemodified',
            $now,
            "id $insql",
            $inparams
        );
        return count($ids);
    }

    return 0;
}
