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
 * Upgrade helper functions for local_forum_ai.
 *
 * Production sites have been observed with a recorded plugin version from a
 * divergent lineage whose schema misses historical columns, so later upgrade
 * steps that anchor new columns on them fail. These helpers reconcile the
 * historical schema of the plugin tables before such steps run: every field
 * is added conditionally, so they are safe to call on healthy schemas.
 *
 * @package     local_forum_ai
 * @category    upgrade
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Ensure every historical field of local_forum_ai_config exists.
 *
 * Fields are (re)created in install.xml order using the exact definitions of
 * the original upgrade steps, so a drifted schema converges to the expected
 * one. Each "previous" anchor is either a field guaranteed earlier by this
 * same function or a field that exists since the table was first created.
 * The replyinlocked field is intentionally NOT handled here: it belongs to
 * the 2026072800 upgrade step, which calls this function first.
 *
 * @param database_manager $dbman The database manager.
 * @return void
 */
function local_forum_ai_reconcile_config_schema(database_manager $dbman): void {
    $table = new xmldb_table('local_forum_ai_config');

    $fields = [
        // Added in 2025111200, anchored on base field "enabled".
        new xmldb_field('enablediainitconversation', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'enabled'),
        // Added in 2026050701, anchored on the previous field of this list.
        new xmldb_field('questionturns', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'enablediainitconversation'),
        // Added in 2025111300, anchored on a field guaranteed above.
        new xmldb_field('allowedroles', XMLDB_TYPE_TEXT, null, null, null, null, null, 'questionturns'),
        // Base column of the original create_table; re-added if drifted away.
        new xmldb_field('reply_message', XMLDB_TYPE_TEXT, null, null, null, null, null, 'allowedroles'),
        // Historical column expected by the 2025111503 step as anchor.
        new xmldb_field('require_approval', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'reply_message'),
        // Added in 2025111503, anchored on require_approval.
        new xmldb_field('graderid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'require_approval'),
        // Added in 2025121202, anchored on base field "timemodified".
        new xmldb_field('usedelay', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timemodified'),
        // Added in 2025121203, anchored on usedelay.
        new xmldb_field('delayminutes', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '0', 'usedelay'),
    ];

    foreach ($fields as $field) {
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    }
}

/**
 * Ensure every historical field of local_forum_ai_pending exists.
 *
 * Same rationale as {@see local_forum_ai_reconcile_config_schema()} but for
 * the pending-approvals table. The postid and action_userid fields are
 * intentionally NOT handled here: they belong to the 2026073000 and
 * 2026080600 upgrade steps respectively, which call this function first.
 * The approved_at column is part of the original create_table, so it is not
 * a historical addition and is not reconciled here.
 *
 * @param database_manager $dbman The database manager.
 * @return void
 */
function local_forum_ai_reconcile_pending_schema(database_manager $dbman): void {
    $table = new xmldb_table('local_forum_ai_pending');

    $fields = [
        // Added in 2025110800, anchored on base field "forumid".
        new xmldb_field('parentpostid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'forumid'),
        // Added in 2025111505, anchored on base field "message".
        new xmldb_field('grade', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'message'),
    ];

    foreach ($fields as $field) {
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    }
}
