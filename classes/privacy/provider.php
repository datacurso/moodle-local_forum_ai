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

namespace local_forum_ai\privacy;

use context;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy subsystem implementation for local_forum_ai.
 *
 * All personal data is scoped to the forum (course module) it belongs to, so
 * every request is resolved against module contexts.
 *
 * Scope note: pending.creator_userid holds the student who triggered the AI
 * response, but the manual approval flow overwrites it with the approving
 * teacher — after approval the student would no longer be found through that
 * column alone. To compensate, every operation also attributes pending rows
 * through the author of parentpostid (the student's post). This can slightly
 * over-attribute (and over-delete) rows towards the student, which is the
 * GDPR-safe direction: the row is AI content about the student's post.
 *
 * @package    local_forum_ai
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    core_userlist_provider {
    /**
     * Describe the types of personal data stored by this plugin.
     *
     * @param collection $collection The initialized collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table(
            'local_forum_ai_config',
            [
                'forumid'         => 'privacy:metadata:local_forum_ai_config:forumid',
                'enabled'         => 'privacy:metadata:local_forum_ai_config:enabled',
                'reply_message'   => 'privacy:metadata:local_forum_ai_config:reply_message',
                'require_approval' => 'privacy:metadata:local_forum_ai_config:require_approval',
                'questionturns'   => 'privacy:metadata:local_forum_ai_config:questionturns',
                'replyinlocked'   => 'privacy:metadata:local_forum_ai_config:replyinlocked',
                'graderid'        => 'privacy:metadata:local_forum_ai_config:graderid',
                'enablediainitconversation' => 'privacy:metadata:local_forum_ai_config:enablediainitconversation',
                'allowedroles'    => 'privacy:metadata:local_forum_ai_config:allowedroles',
                'usedelay'        => 'privacy:metadata:local_forum_ai_config:usedelay',
                'delayminutes'    => 'privacy:metadata:local_forum_ai_config:delayminutes',
                'timecreated'     => 'privacy:metadata:local_forum_ai_config:timecreated',
                'timemodified'    => 'privacy:metadata:local_forum_ai_config:timemodified',
            ],
            'privacy:metadata:local_forum_ai_config'
        );

        $collection->add_database_table(
            'local_forum_ai_pending',
            [
                'creator_userid' => 'privacy:metadata:local_forum_ai_pending:creator_userid',
                'discussionid'   => 'privacy:metadata:local_forum_ai_pending:discussionid',
                'forumid'        => 'privacy:metadata:local_forum_ai_pending:forumid',
                'parentpostid'   => 'privacy:metadata:local_forum_ai_pending:parentpostid',
                'message'        => 'privacy:metadata:local_forum_ai_pending:message',
                'subject'        => 'privacy:metadata:local_forum_ai_pending:subject',
                'grade'          => 'privacy:metadata:local_forum_ai_pending:grade',
                'status'         => 'privacy:metadata:local_forum_ai_pending:status',
                'approved_at'    => 'privacy:metadata:local_forum_ai_pending:approved_at',
                'approval_token' => 'privacy:metadata:local_forum_ai_pending:approval_token',
                'timecreated'    => 'privacy:metadata:local_forum_ai_pending:timecreated',
                'timemodified'   => 'privacy:metadata:local_forum_ai_pending:timemodified',
            ],
            'privacy:metadata:local_forum_ai_pending'
        );

        $collection->add_database_table(
            'local_forum_ai_queue',
            [
                'type'          => 'privacy:metadata:local_forum_ai_queue:type',
                'payload'       => 'privacy:metadata:local_forum_ai_queue:payload',
                'timecreated'   => 'privacy:metadata:local_forum_ai_queue:timecreated',
                'timetoprocess' => 'privacy:metadata:local_forum_ai_queue:timetoprocess',
                'processed'     => 'privacy:metadata:local_forum_ai_queue:processed',
            ],
            'privacy:metadata:local_forum_ai_queue'
        );

        // Forum post content is sent to the external AI provider to generate replies and evaluations.
        $collection->add_external_location_link(
            'datacurso_ai',
            [
                'userid' => 'privacy:metadata:datacurso_ai:userid',
                'author_name' => 'privacy:metadata:datacurso_ai:author_name',
                'post_content' => 'privacy:metadata:datacurso_ai:post_content',
                'thread_history' => 'privacy:metadata:datacurso_ai:thread_history',
                'course_activity' => 'privacy:metadata:datacurso_ai:course_activity',
            ],
            'privacy:metadata:datacurso_ai'
        );

        return $collection;
    }

    /**
     * Get contexts containing user data for a specific user.
     *
     * @param int $userid The user to search.
     * @return contextlist The list of module contexts holding the user's data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $forummodule = (int) $DB->get_field('modules', 'id', ['name' => 'forum']);

        // Pending records: pending.forumid holds the forum instance id. Rows are
        // attributed to the creator OR to the author of the parent post (approval
        // overwrites creator_userid with the approving teacher).
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_forum_ai_pending} p
               JOIN {course_modules} cm ON cm.instance = p.forumid AND cm.module = :forummod
               JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :modlevel
          LEFT JOIN {forum_posts} fp ON fp.id = p.parentpostid
              WHERE p.creator_userid = :uid1 OR fp.userid = :uid2",
            ['forummod' => $forummodule, 'modlevel' => CONTEXT_MODULE, 'uid1' => $userid, 'uid2' => $userid]
        );

        // Config records: the recorded grader.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_forum_ai_config} c
               JOIN {course_modules} cm ON cm.instance = c.forumid AND cm.module = :forummod
               JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :modlevel
              WHERE c.graderid = :uid",
            ['forummod' => $forummodule, 'modlevel' => CONTEXT_MODULE, 'uid' => $userid]
        );

        // Queue rows: the payload references a post/discussion authored by the user.
        $cmids = self::queue_cmids_for_user($userid);
        if (!empty($cmids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
            $contextlist->add_from_sql(
                "SELECT ctx.id
                   FROM {context} ctx
                  WHERE ctx.contextlevel = :modlevel AND ctx.instanceid $insql",
                ['modlevel' => CONTEXT_MODULE] + $inparams
            );
        }

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the users who have data in this context.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $cmid = (int) $context->instanceid;
        $instanceid = self::instanceid_for_cmid($cmid);

        if ($instanceid) {
            // Pending: the creator of each AI response.
            $userlist->add_from_sql(
                'creator_userid',
                'SELECT creator_userid FROM {local_forum_ai_pending} WHERE forumid = :fid',
                ['fid' => $instanceid]
            );

            // Pending: the author of the parent post (approval overwrites creator_userid).
            $userlist->add_from_sql(
                'userid',
                'SELECT fp.userid
                   FROM {local_forum_ai_pending} p
                   JOIN {forum_posts} fp ON fp.id = p.parentpostid
                  WHERE p.forumid = :fid',
                ['fid' => $instanceid]
            );

            // Config: the recorded grader.
            $userlist->add_from_sql(
                'graderid',
                'SELECT graderid FROM {local_forum_ai_config} WHERE forumid = :fid AND graderid IS NOT NULL',
                ['fid' => $instanceid]
            );
        }

        // Queue: users derived from the payload's post or discussion author.
        foreach (self::queue_payloads() as $data) {
            if ((int) ($data->cmid ?? 0) !== $cmid) {
                continue;
            }
            $payloaduser = self::queue_user_for_payload($data);
            if ($payloaduser) {
                $userlist->add_user($payloaduser);
            }
        }
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cmid = (int) $context->instanceid;
            $instanceid = self::instanceid_for_cmid($cmid);

            if ($instanceid) {
                // Attributed to the creator OR to the author of the parent post.
                $pending = $DB->get_records_sql(
                    "SELECT p.*
                       FROM {local_forum_ai_pending} p
                  LEFT JOIN {forum_posts} fp ON fp.id = p.parentpostid
                      WHERE p.forumid = :fid AND (p.creator_userid = :uid1 OR fp.userid = :uid2)",
                    ['fid' => $instanceid, 'uid1' => $user->id, 'uid2' => $user->id]
                );
                if (!empty($pending)) {
                    writer::with_context($context)->export_data(
                        [get_string('privacy:metadata:local_forum_ai_pending', 'local_forum_ai')],
                        (object) ['entries' => array_values($pending)]
                    );
                }

                $config = $DB->get_records('local_forum_ai_config', [
                    'forumid' => $instanceid,
                    'graderid' => $user->id,
                ]);
                if (!empty($config)) {
                    writer::with_context($context)->export_data(
                        [get_string('privacy:metadata:local_forum_ai_config', 'local_forum_ai')],
                        (object) ['entries' => array_values($config)]
                    );
                }
            }

            $queue = [];
            foreach (self::queue_payloads(true) as $row) {
                $data = json_decode($row->payload);
                if (!$data) {
                    debugging('Error decoding Forum AI queue payload for row ' . $row->id, DEBUG_DEVELOPER);
                    continue;
                }
                if ((int) ($data->cmid ?? 0) !== $cmid) {
                    continue;
                }
                if (self::queue_user_for_payload($data) === (int) $user->id) {
                    $queue[] = $row;
                }
            }
            if (!empty($queue)) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:metadata:local_forum_ai_queue', 'local_forum_ai')],
                    (object) ['entries' => array_values($queue)]
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        $cmid = (int) $context->instanceid;
        $instanceid = self::instanceid_for_cmid($cmid);

        if ($instanceid) {
            $DB->delete_records('local_forum_ai_pending', ['forumid' => $instanceid]);
            // The config row is not personal data beyond the grader reference: keep it, null the grader.
            $DB->set_field_select(
                'local_forum_ai_config',
                'graderid',
                null,
                'forumid = :fid',
                ['fid' => $instanceid]
            );
        }
        self::delete_queue_rows($cmid);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_module) {
                self::delete_users_in_module($context, [(int) $user->id]);
            }
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and users to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_module) {
            self::delete_users_in_module($context, array_map('intval', $userlist->get_userids()));
        }
    }

    /**
     * Deletes/anonymises the given users' data within one module context.
     *
     * The users' pending rows and queue rows are removed. When a user only
     * appears as the recorded grader, the reference is nulled rather than
     * deleting the config row, which belongs to the forum.
     *
     * @param \context_module $context The module context.
     * @param int[] $userids User ids to remove.
     * @return void
     */
    private static function delete_users_in_module(\context_module $context, array $userids): void {
        global $DB;

        if (empty($userids)) {
            return;
        }

        $cmid = (int) $context->instanceid;
        $instanceid = self::instanceid_for_cmid($cmid);

        if ($instanceid) {
            // The users' pending rows are deleted, whether attributed through the
            // creator or through the parent post's author (approval overwrites
            // creator_userid; over-deleting towards the student is the safe side).
            [$insqlcreator, $creatorparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'cu');
            [$insqlauthor, $authorparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'au');
            $DB->delete_records_select(
                'local_forum_ai_pending',
                "forumid = :fid AND (creator_userid $insqlcreator
                    OR parentpostid IN (SELECT id FROM {forum_posts} WHERE userid $insqlauthor))",
                ['fid' => $instanceid] + $creatorparams + $authorparams
            );

            // Grader references are anonymised, the config row is kept.
            [$insqlgrader, $graderparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'gu');
            $DB->set_field_select(
                'local_forum_ai_config',
                'graderid',
                null,
                "forumid = :fid AND graderid $insqlgrader",
                ['fid' => $instanceid] + $graderparams
            );
        }

        self::delete_queue_rows($cmid, $userids);
    }

    /**
     * Resolves the forum instance id for a course-module id.
     *
     * @param int $cmid Course module id.
     * @return int|null Instance id, or null if the module no longer exists.
     */
    private static function instanceid_for_cmid(int $cmid): ?int {
        $cm = get_coursemodule_from_id('forum', $cmid, 0, false, IGNORE_MISSING);
        return $cm ? (int) $cm->instance : null;
    }

    /**
     * Returns the decoded payloads of all queue rows (or the raw rows).
     *
     * @param bool $raw When true, returns the raw DB rows instead of decoded payloads.
     * @return array
     */
    private static function queue_payloads(bool $raw = false): array {
        global $DB;

        $rows = $DB->get_records('local_forum_ai_queue');
        if ($raw) {
            return $rows;
        }
        $out = [];
        foreach ($rows as $row) {
            $data = json_decode($row->payload);
            if ($data) {
                $out[] = $data;
            } else {
                debugging('Error decoding Forum AI queue payload for row ' . $row->id, DEBUG_DEVELOPER);
            }
        }
        return $out;
    }

    /**
     * Resolves the user a queue payload is attributed to.
     *
     * The payload carries no userid: post payloads are attributed to the post
     * author and discussion payloads to the discussion starter.
     *
     * @param \stdClass $data Decoded queue payload.
     * @return int|null User id, or null when the reference no longer resolves.
     */
    private static function queue_user_for_payload(\stdClass $data): ?int {
        global $DB;

        if (!empty($data->postid)) {
            $userid = $DB->get_field('forum_posts', 'userid', ['id' => (int) $data->postid], IGNORE_MISSING);
            return $userid ? (int) $userid : null;
        }
        if (!empty($data->discussionid)) {
            $userid = $DB->get_field('forum_discussions', 'userid', ['id' => (int) $data->discussionid], IGNORE_MISSING);
            return $userid ? (int) $userid : null;
        }
        return null;
    }

    /**
     * Returns the cmids referenced by queue rows attributed to a given user.
     *
     * @param int $userid User id.
     * @return int[]
     */
    private static function queue_cmids_for_user(int $userid): array {
        $cmids = [];
        foreach (self::queue_payloads() as $data) {
            if (empty($data->cmid)) {
                continue;
            }
            if (self::queue_user_for_payload($data) === $userid) {
                $cmids[(int) $data->cmid] = (int) $data->cmid;
            }
        }
        return array_values($cmids);
    }

    /**
     * Deletes queue rows for a cmid, optionally restricted to given users.
     *
     * @param int $cmid Course module id.
     * @param int[]|null $userids When given, only rows attributed to these users are removed.
     * @return void
     */
    private static function delete_queue_rows(int $cmid, ?array $userids = null): void {
        global $DB;

        $ids = [];
        foreach (self::queue_payloads(true) as $row) {
            $data = json_decode($row->payload);
            if (!$data) {
                debugging('Error decoding Forum AI queue payload for row ' . $row->id, DEBUG_DEVELOPER);
                continue;
            }
            if ((int) ($data->cmid ?? 0) !== $cmid) {
                continue;
            }
            if ($userids !== null) {
                $payloaduser = self::queue_user_for_payload($data);
                if ($payloaduser === null || !in_array($payloaduser, $userids, true)) {
                    continue;
                }
            }
            $ids[] = $row->id;
        }
        if (!empty($ids)) {
            $DB->delete_records_list('local_forum_ai_queue', 'id', $ids);
        }
    }
}
