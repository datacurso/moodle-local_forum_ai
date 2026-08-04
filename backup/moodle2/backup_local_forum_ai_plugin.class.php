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
 * Backup plugin for local_forum_ai.
 *
 * @package    local_forum_ai
 * @category   backup
 * @copyright  2025 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_forum_ai_plugin extends backup_local_plugin {
    /**
     * Define the structure to include in the course backup.
     *
     * @return backup_plugin_element
     */
    protected function define_course_plugin_structure() {
        $plugin = $this->get_plugin_element(null);
        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        // Wrapper for all forum AI configurations.
        $configs = new backup_nested_element('forum_ai_configs');
        $pluginwrapper->add_child($configs);

        $config = new backup_nested_element('forum_ai_config', ['id'], [
            'forumid', 'enabled', 'reply_message', 'require_approval',
            'allowedroles', 'enablediainitconversation', 'questionturns',
            'graderid', 'usedelay', 'delayminutes',
            'timecreated', 'timemodified',
        ]);
        $configs->add_child($config);

        // Wrapper for pending AI messages.
        $pendings = new backup_nested_element('forum_ai_pendings');
        $pluginwrapper->add_child($pendings);

        $pending = new backup_nested_element('forum_ai_pending', ['id'], [
            'discussionid', 'forumid', 'creator_userid', 'subject', 'message',
            'status', 'approval_token', 'timecreated', 'timemodified', 'approved_at',
        ]);
        $pendings->add_child($pending);

        // Sources.
        $config->set_source_sql('
            SELECT c.*
              FROM {local_forum_ai_config} c
              JOIN {forum} f ON f.id = c.forumid
             WHERE f.course = ?
        ', [backup::VAR_COURSEID]);

        $pending->set_source_sql('
            SELECT p.*
              FROM {local_forum_ai_pending} p
              JOIN {forum} f ON f.id = p.forumid
             WHERE f.course = ?
        ', [backup::VAR_COURSEID]);

        return $plugin;
    }
}
