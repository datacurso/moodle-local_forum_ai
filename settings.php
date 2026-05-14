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
 * Plugin administration pages are defined here.
 *
 * @package     local_forum_ai
 * @category    admin
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $defaults = [
        'enableforumai' => 1,
        'default_enabled' => 1,
        'default_require_approval' => 1,
        'default_reply_message' => get_string('default_reply_message', 'local_forum_ai'),
        'default_enablediainitconversation' => 0,
        'default_usedelay' => 0,
        'default_delayminutes' => 60,
        'default_question_turns' => 1,
    ];

    foreach ($defaults as $name => $value) {
        $current = get_config('local_forum_ai', $name);
        if ($current === false || $current === '') {
            set_config($name, $value, 'local_forum_ai');
        }
    }

    $settings = new admin_settingpage('local_forum_ai_settings', new lang_string('pluginname', 'local_forum_ai'));

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configcheckbox(
            'local_forum_ai/enableforumai',
            get_string('enableforumai', 'local_forum_ai'),
            get_string('enableforumai_desc', 'local_forum_ai'),
            1
        ));

        $globalenableaisetting = new admin_setting_configcheckbox(
            'local_forum_ai/default_enabled',
            get_string('defaultenableai', 'local_forum_ai'),
            get_string('defaultenableai_desc', 'local_forum_ai'),
            1
        );
        $globalenableaisetting->set_updatedcallback(function (string $settingname): void {
            if ($settingname !== 'local_forum_ai/default_enabled') {
                return;
            }

            if (!\local_forum_ai\utils::is_global_ai_enabled()) {
                \local_forum_ai\utils::disable_all_forums_ai();
            }
        });
        $settings->add($globalenableaisetting);

        $settings->add(new admin_setting_configcheckbox(
            'local_forum_ai/default_enablediainitconversation',
            get_string('enablediainitconversation', 'local_forum_ai'),
            get_string('enablediainitconversation_help', 'local_forum_ai'),
            0
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_forum_ai/default_require_approval',
            get_string('require_approval', 'local_forum_ai'),
            '',
            1
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_forum_ai/default_usedelay',
            get_string('usedelay', 'local_forum_ai'),
            get_string('usedelay_help', 'local_forum_ai'),
            0
        ));

        $settings->add(new admin_setting_configtext(
            'local_forum_ai/default_delayminutes',
            get_string('delayminutes', 'local_forum_ai'),
            get_string('delayminutes_help', 'local_forum_ai'),
            60,
            PARAM_INT
        ));

        $questionturnoptions = [
            0 => '0',
            1 => '1',
            2 => '2',
            3 => '3',
        ];
        $settings->add(new admin_setting_configselect(
            'local_forum_ai/default_question_turns',
            get_string('questionturns', 'local_forum_ai'),
            get_string('questionturns_help', 'local_forum_ai'),
            1,
            $questionturnoptions
        ));

        $settings->add(new admin_setting_configtextarea(
            'local_forum_ai/default_reply_message',
            get_string('reply_message', 'local_forum_ai'),
            '',
            get_string('default_reply_message', 'local_forum_ai'),
            PARAM_TEXT,
            3,
            3
        ));

        $settings->hide_if('local_forum_ai/default_enabled', 'local_forum_ai/enableforumai', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_enablediainitconversation', 'local_forum_ai/enableforumai', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_require_approval', 'local_forum_ai/enableforumai', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_usedelay', 'local_forum_ai/enableforumai', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_delayminutes', 'local_forum_ai/enableforumai', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_question_turns', 'local_forum_ai/enableforumai', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_reply_message', 'local_forum_ai/enableforumai', 'eq', 0);

        $settings->hide_if('local_forum_ai/default_enablediainitconversation', 'local_forum_ai/default_enabled', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_require_approval', 'local_forum_ai/default_enabled', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_usedelay', 'local_forum_ai/default_enabled', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_delayminutes', 'local_forum_ai/default_enabled', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_question_turns', 'local_forum_ai/default_enabled', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_reply_message', 'local_forum_ai/default_enabled', 'eq', 0);

        $settings->hide_if('local_forum_ai/default_usedelay', 'local_forum_ai/default_require_approval', 'eq', 1);
        $settings->hide_if('local_forum_ai/default_delayminutes', 'local_forum_ai/default_require_approval', 'eq', 1);
        $settings->hide_if('local_forum_ai/default_delayminutes', 'local_forum_ai/default_usedelay', 'neq', 1);
    }

    $ADMIN->add('localplugins', $settings);
}
