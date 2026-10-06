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
 * Gets the list of pending responses the user may manage.
 *
 * Rows are limited to forums visible to the user where they hold
 * local/forum_ai:approveresponses, and to discussions of groups they can
 * access (see local_forum_ai_filter_rows_for_user()).
 *
 * @package local_forum_ai
 * @param int $courseid Course ID.
 * @param int $forumid (optional) Forum ID to filter.
 * @param int $userid (optional) User the rows are listed for; defaults to the current user.
 * @return array list of objects with pending data.
 */
function local_forum_ai_get_pending(int $courseid, int $forumid = 0, int $userid = 0) {
    global $DB;

    // All user name fields so fullname() can be used on the returned rows.
    $usernamefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

    $sql = "SELECT p.*, d.name AS discussionname, f.name AS forumname,
                   c.fullname AS coursename, {$usernamefields},
                   fp.subject AS discussionsubject, fp.message AS discussionmessage, fp.messageformat,
                   d.groupid AS discussiongroupid, cm.id AS cmid
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

    return local_forum_ai_filter_rows_for_user($DB->get_records_sql($sql, $params), $courseid, $userid);
}

/**
 * Gets the list of response history the user may manage.
 *
 * Same access rules as local_forum_ai_get_pending().
 *
 * @package local_forum_ai
 * @param int $courseid Course ID.
 * @param int $forumid (optional) Forum ID to filter.
 * @param int $userid (optional) User the rows are listed for; defaults to the current user.
 * @return array list of response objects.
 */
function local_forum_ai_get_history(int $courseid, int $forumid = 0, int $userid = 0) {
    global $DB;

    // All user name fields so fullname() can be used on the returned rows:
    // plain aliases for the creator (the originating student) and
    // 'action'-prefixed aliases for the user who approved/rejected the row.
    $usernamefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
    $actionnamefields = \core_user\fields::for_name()->get_sql('au', false, 'action', '', false)->selects;

    $sql = "SELECT p.*, d.name AS discussionname, f.name AS forumname, c.fullname AS coursename,
                   {$usernamefields}, {$actionnamefields}, d.groupid AS discussiongroupid, cm.id AS cmid
              FROM {local_forum_ai_pending} p
              JOIN {forum_discussions} d ON d.id = p.discussionid
              JOIN {forum} f ON f.id = p.forumid
              JOIN {course} c ON c.id = f.course
              JOIN {course_modules} cm ON cm.instance = f.id AND cm.module = (
                    SELECT id FROM {modules} WHERE name = 'forum'
              )
              JOIN {user} u ON u.id = p.creator_userid
         LEFT JOIN {user} au ON au.id = p.action_userid
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

    return local_forum_ai_filter_rows_for_user($DB->get_records_sql($sql, $params), $courseid, $userid);
}

/**
 * Keeps only the listing rows the user may manage.
 *
 * Filtering happens per forum in PHP because each forum of the course may use
 * a different group mode and capability overrides: a row is kept only when its
 * forum is visible to the user, the user holds local/forum_ai:approveresponses
 * in the forum context and can access the discussion group.
 *
 * @package local_forum_ai
 * @param array $records Rows carrying cmid and discussiongroupid, keyed by row id.
 * @param int $courseid Course ID the rows belong to.
 * @param int $userid User to evaluate; 0 for the current user.
 * @return array The accessible rows, keys and order preserved.
 */
function local_forum_ai_filter_rows_for_user(array $records, int $courseid, int $userid = 0): array {
    global $USER;

    if (empty($records)) {
        return [];
    }

    $userid = $userid ?: (int) $USER->id;
    $modinfo = get_fast_modinfo($courseid, $userid);
    $course = $modinfo->get_course();

    // Per forum: false when the forum is not accessible, otherwise the result of
    // get_allowed_discussion_groupids() (null meaning no group restriction).
    $allowedbycm = [];
    $result = [];
    foreach ($records as $id => $record) {
        $cmid = (int) $record->cmid;
        if (!array_key_exists($cmid, $allowedbycm)) {
            $allowedbycm[$cmid] = false;
            $cm = $modinfo->get_cms()[$cmid] ?? null;
            $canmanage = $cm && $cm->uservisible
                && has_capability('local/forum_ai:approveresponses', context_module::instance($cmid), $userid);
            if ($canmanage) {
                $allowedbycm[$cmid] = \local_forum_ai\local\group_access::get_allowed_discussion_groupids(
                    $cm,
                    $course,
                    $userid
                );
            }
        }

        $allowed = $allowedbycm[$cmid];
        if ($allowed === false) {
            continue;
        }
        if ($allowed !== null && !in_array((int) $record->discussiongroupid, $allowed, true)) {
            continue;
        }
        $result[$id] = $record;
    }

    return $result;
}

/**
 * Marks pending AI responses of expired forums as expired.
 *
 * Scoped to one course (and optionally one forum): opening the pending list
 * of a course must never touch other courses. Rows are kept for traceability
 * with status 'expired' instead of being deleted, so they reach the history.
 * Expiry criterion: the forum cut-off date has passed, or the due date has
 * passed when no cut-off date is set. It must stay identical to
 * \local_forum_ai\utils::is_forum_deadline_reached(), the publication barrier.
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

/**
 * Marks one pending AI response as expired.
 *
 * Used when a single row is found past the forum deadline outside the bulk
 * cleanup (approval attempt, review page). Only rows still pending change.
 *
 * @package local_forum_ai
 * @param \stdClass $pending Pending response record.
 * @return void
 */
function local_forum_ai_expire_pending(\stdClass $pending): void {
    global $DB;

    $DB->execute(
        "UPDATE {local_forum_ai_pending}
            SET status = 'expired', timemodified = :now
          WHERE id = :id AND status = 'pending'",
        ['now' => time(), 'id' => $pending->id]
    );
}

/**
 * Adds a rating on behalf of a specific user through Moodle's standard rating API.
 *
 * Single shared rating writer for the plugin: it delegates the whole validation
 * chain to rating_manager::add_rating(), which runs the component callbacks
 * (forum_rating_validate: assessment date window, group membership, scale
 * bounds, self-rating, post visibility) and pushes the grade to the gradebook.
 *
 * The rating subsystem hardcodes the rater to $USER and the gradebook push
 * logs \core\event\user_graded against the current user, so the writer
 * switches to the rater when needed and restores the original user in the
 * finally block. It never calls debugging(): failures are reported through
 * the returned error code only, callers decide how to surface them.
 *
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $component component name
 * @param string $ratingarea rating area
 * @param int $itemid the item id
 * @param int $scaleid the scale id
 * @param int $userrating the rating value
 * @param int $rateduserid the rated user id
 * @param int $aggregationmethod the aggregation method
 * @param int $rateruserid the user ID who is giving the rating
 * @return stdClass result object with success/error properties
 */
function local_forum_ai_add_rating(
    $cm,
    $context,
    $component,
    $ratingarea,
    $itemid,
    $scaleid,
    $userrating,
    $rateduserid,
    $aggregationmethod,
    $rateruserid
) {
    global $CFG, $USER;

    require_once($CFG->dirroot . '/rating/lib.php');

    $result = new stdClass();

    // Parity with core's rating entry point (rating/rate.php), which requires
    // moodle/rating:rate before reaching the component permission callbacks.
    if (!has_capability('moodle/rating:rate', $context, $rateruserid)) {
        $result->error = 'ratepermissiondenied';
        return $result;
    }

    // Switch to the rater when the current user differs (task/queue paths).
    $originaluser = null;
    if ((int) $USER->id !== (int) $rateruserid) {
        $rater = \core_user::get_user($rateruserid);
        if (!$rater || !empty($rater->deleted) || !empty($rater->suspended)) {
            $result->error = 'invaliduser';
            return $result;
        }
        $originaluser = $USER;
        \core\cron::setup_user($rater);
    }

    try {
        $rm = new rating_manager();
        return $rm->add_rating(
            $cm,
            $context,
            $component,
            $ratingarea,
            $itemid,
            $scaleid,
            $userrating,
            $rateduserid,
            $aggregationmethod
        );
    } catch (moodle_exception $e) {
        // The component validation callback (forum_rating_validate) reports
        // window, group, scale and visibility failures as rating_exception.
        $result->error = (string) $e->errorcode;
        return $result;
    } finally {
        if ($originaluser !== null) {
            \core\cron::setup_user($originaluser);
        }
    }
}

/**
 * Repairs history rows that stored the managing user as creator.
 *
 * Automatic mode used to store the grader in creator_userid (and left
 * action_userid empty); rows managed before the action_userid column existed
 * had the approving teacher there too. For approved rows with no manager whose
 * creator is the author of the published AI post, the creator is moved to
 * action_userid and the author of the parent post (the originating student)
 * becomes the creator. Rows whose parent post no longer exists are skipped.
 *
 * @package local_forum_ai
 * @return int Number of repaired rows.
 */
function local_forum_ai_repair_auto_mode_identities(): int {
    global $DB;

    $sql = "SELECT p.id, p.creator_userid, pp.userid AS parentauthorid
              FROM {local_forum_ai_pending} p
              JOIN {forum_posts} ap ON ap.id = p.postid AND ap.userid = p.creator_userid
              JOIN {forum_posts} pp ON pp.id = p.parentpostid
             WHERE p.status = :status
               AND p.action_userid IS NULL
               AND p.postid IS NOT NULL";

    $repaired = 0;
    $rows = $DB->get_recordset_sql($sql, ['status' => 'approved']);
    foreach ($rows as $row) {
        $DB->update_record('local_forum_ai_pending', (object) [
            'id' => $row->id,
            'creator_userid' => $row->parentauthorid,
            'action_userid' => $row->creator_userid,
        ]);
        $repaired++;
    }
    $rows->close();

    return $repaired;
}
