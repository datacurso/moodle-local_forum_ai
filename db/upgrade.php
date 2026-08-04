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
 * Plugin upgrade steps are defined here.
 *
 * @package     local_forum_ai
 * @category    upgrade
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute local_forum_ai upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_forum_ai_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // For further information please read {@link https://docs.moodle.org/dev/Upgrade_API}.
    // You will also have to create the db/install.xml file by using the XMLDB Editor.
    // Documentation for the XMLDB Editor can be found at {@link https://docs.moodle.org/dev/XMLDB_editor}.

    if ($oldversion < 2025091611) {
        $table = new xmldb_table('local_forum_ai_config');

        // Adding fields to table local_forum_ai_config.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('forumid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('bot_userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('reply_message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('ai_model', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table local_forum_ai_config.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('forumid_unique', XMLDB_KEY_UNIQUE, ['forumid']);

        // Conditionally launch create table for local_forum_ai_config.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_forum_ai_pending');

        // Adding fields to table local_forum_ai_pending.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('discussionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('forumid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('creator_userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('bot_userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('subject', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('message', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('approval_token', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('approved_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Adding keys to table local_forum_ai_pending.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('discussionid_fk', XMLDB_KEY_FOREIGN, ['discussionid'], 'forum_discussions', ['id']);
        $table->add_key('approval_token_unique', XMLDB_KEY_UNIQUE, ['approval_token']);

        // Conditionally launch create table for local_forum_ai_pending.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025091611, 'local', 'forum_ai');
    }

    if ($oldversion < 2025110800) {
        // Define field parentpostid to be added to local_forum_ai_pending.
        $table = new xmldb_table('local_forum_ai_pending');
        $field = new xmldb_field('parentpostid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'forumid');

        // Conditionally launch add field parentpostid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Forum_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2025110800, 'local', 'forum_ai');
    }

    if ($oldversion < 2025111200) {
        // Define field enablediainitconversation to be added to local_forum_ai_config.
        $table = new xmldb_table('local_forum_ai_config');
        $field = new xmldb_field('enablediainitconversation', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'enabled');

        // Conditionally launch add field enablediainitconversation.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Forum_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2025111200, 'local', 'forum_ai');
    }

    if ($oldversion < 2025111300) {
        $table = new xmldb_table('local_forum_ai_config');
        $field = new xmldb_field('allowedroles', XMLDB_TYPE_TEXT, null, null, null, null, null, 'enablediainitconversation');

        // Add field if it does not exist.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Forum_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2025111300, 'local', 'forum_ai');
    }

    if ($oldversion < 2025111503) {
        // Define field graderid to be added to local_forum_ai_config.
        $table = new xmldb_table('local_forum_ai_config');
        $field = new xmldb_field('graderid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'require_approval');

        // Conditionally launch add field graderid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Forum_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2025111503, 'local', 'forum_ai');
    }

    if ($oldversion < 2025111505) {
        // Define field grade to be added to local_forum_ai_pending.
        $table = new xmldb_table('local_forum_ai_pending');
        $field = new xmldb_field('grade', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'message');

        // Conditionally launch add field grade.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Forum_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2025111505, 'local', 'forum_ai');
    }

    if ($oldversion < 2025121201) {
        // Define table local_forum_ai_queue to be created.
        $table = new xmldb_table('local_forum_ai_queue');

        // Adding fields to table local_forum_ai_queue.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timetoprocess', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('processed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table local_forum_ai_queue.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for local_forum_ai_queue.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Forum_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2025121201, 'local', 'forum_ai');
    }

    if ($oldversion < 2025121202) {
        // Define field usedelay to be added to local_forum_ai_config.
        $table = new xmldb_table('local_forum_ai_config');
        $field = new xmldb_field('usedelay', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timemodified');

        // Conditionally launch add field usedelay.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Forum_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2025121202, 'local', 'forum_ai');
    }

    if ($oldversion < 2025121203) {
        // Define field delayminutes to be added to local_forum_ai_config.
        $table = new xmldb_table('local_forum_ai_config');
        $field = new xmldb_field('delayminutes', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '0', 'usedelay');

        // Conditionally launch add field delayminutes.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Forum_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2025121203, 'local', 'forum_ai');
    }

    if ($oldversion < 2026050701) {
        // Define field questionturns to be added to local_forum_ai_config.
        $table = new xmldb_table('local_forum_ai_config');
        $field = new xmldb_field('questionturns', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'enablediainitconversation');

        // Conditionally launch add field questionturns.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        if (get_config('local_forum_ai', 'default_enablediainitconversation') === false) {
            set_config('default_enablediainitconversation', 0, 'local_forum_ai');
        }
        if (get_config('local_forum_ai', 'default_usedelay') === false) {
            set_config('default_usedelay', 0, 'local_forum_ai');
        }
        if (get_config('local_forum_ai', 'default_delayminutes') === false) {
            set_config('default_delayminutes', 60, 'local_forum_ai');
        }
        if (get_config('local_forum_ai', 'default_question_turns') === false) {
            set_config('default_question_turns', 1, 'local_forum_ai');
        }

        // Forum_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2026050701, 'local', 'forum_ai');
    }

    if ($oldversion < 2026050702) {
        $globalenabled = get_config('local_forum_ai', 'enableforumai');
        if ($globalenabled === false || $globalenabled === '') {
            set_config('enableforumai', 1, 'local_forum_ai');
        }

        $defaultenabled = get_config('local_forum_ai', 'default_enabled');
        if ($defaultenabled === false || $defaultenabled === '' || (int)$defaultenabled === 0) {
            set_config('default_enabled', 1, 'local_forum_ai');
        }

        // Forum_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2026050702, 'local', 'forum_ai');
    }

    return true;
}
