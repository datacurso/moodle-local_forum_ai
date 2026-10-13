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

use local_forum_ai\local\tenant_config;
use local_forum_ai\local\tenant_context;

// The same standard settings page serves Moodle LMS and Moodle Workplace (URL
// /admin/settings.php?section=local_forum_ai_settings). On a plain LMS the values are the site wide plugin
// configuration. On Workplace every tenant is its own platform: the tenant aware setting types read and write the
// values of the active tenant (the tenant selected with the Workplace tenant switcher, or the own tenant of a tenant
// administrator), no site defaults are seeded and nothing at site level can change all the tenants at once.
$tenantaware = tenant_context::is_tenancy_available();

if (!$tenantaware && $hassiteconfig) {
    foreach (tenant_config::SETTING_NAMES as $name) {
        $current = get_config('local_forum_ai', $name);
        if ($current === false || $current === '') {
            set_config($name, tenant_config::get_plugin_default($name), 'local_forum_ai');
        }
    }
}

// Site administrators find the page under Site administration > Plugins > Local plugins. Tenant administrators do not
// hold moodle/site:config and cannot see Local plugins, so they get it under the core 'ai' category. Every user sees
// exactly one entry; access is granted through local/forum_ai:managetenantsettings.
$parentcategory = null;
if ($hassiteconfig) {
    $parentcategory = 'localplugins';
} else if ($tenantaware && $ADMIN->locate('ai')) {
    $parentcategory = 'ai';
}

if ($parentcategory !== null) {
    $checkbox = $tenantaware ? '\local_forum_ai\admin\tenant_configcheckbox' : 'admin_setting_configcheckbox';
    $text = $tenantaware ? '\local_forum_ai\admin\tenant_configtext' : 'admin_setting_configtext';
    $textarea = $tenantaware ? '\local_forum_ai\admin\tenant_configtextarea' : 'admin_setting_configtextarea';
    $select = $tenantaware ? '\local_forum_ai\admin\tenant_configselect' : 'admin_setting_configselect';

    $settings = new admin_settingpage(
        'local_forum_ai_settings',
        new lang_string('pluginname', 'local_forum_ai'),
        $tenantaware ? 'local/forum_ai:managetenantsettings' : 'moodle/site:config'
    );

    if ($ADMIN->fulltree) {
        if ($tenantaware) {
            $settings->add(new \local_forum_ai\admin\tenant_notice('local_forum_ai/tenantnotice'));
        }

        $settings->add(new $checkbox(
            'local_forum_ai/enableforumai',
            get_string('enableforumai', 'local_forum_ai'),
            get_string('enableforumai_desc', 'local_forum_ai'),
            1
        ));

        $globalenableaisetting = new $checkbox(
            'local_forum_ai/default_enabled',
            get_string('defaultenableai', 'local_forum_ai'),
            get_string('defaultenableai_desc', 'local_forum_ai'),
            1
        );
        // The callback is bound to this setting only and Moodle runs it only when the
        // value changed, so no setting-name guard is needed (Moodle passes the full
        // name "s_local_forum_ai_default_enabled", not "local_forum_ai/default_enabled").
        // Under Workplace it only acts on the forums of the tenant that saved the page.
        $globalenableaisetting->set_updatedcallback(function (): void {
            $tenantid = tenant_context::get_current_tenant_id();
            if (tenant_context::is_tenancy_available() && $tenantid <= 0) {
                return;
            }
            if (!\local_forum_ai\utils::is_global_ai_enabled($tenantid)) {
                \local_forum_ai\utils::disable_all_forums_ai($tenantid);
            }
        });
        $settings->add($globalenableaisetting);

        $settings->add(new $checkbox(
            'local_forum_ai/default_enablediainitconversation',
            get_string('enablediainitconversation', 'local_forum_ai'),
            get_string('enablediainitconversation_help', 'local_forum_ai'),
            0
        ));

        $settings->add(new $checkbox(
            'local_forum_ai/default_require_approval',
            get_string('require_approval', 'local_forum_ai'),
            '',
            1
        ));

        $settings->add(new $checkbox(
            'local_forum_ai/default_usedelay',
            get_string('usedelay', 'local_forum_ai'),
            get_string('usedelay_help', 'local_forum_ai'),
            0
        ));

        $settings->add(new $text(
            'local_forum_ai/default_delayminutes',
            get_string('delayminutes', 'local_forum_ai'),
            get_string('delayminutes_help', 'local_forum_ai'),
            60,
            PARAM_INT
        ));

        $settings->add(new $checkbox(
            'local_forum_ai/default_replyinlocked',
            get_string('default_replyinlocked', 'local_forum_ai'),
            get_string('default_replyinlocked_desc', 'local_forum_ai'),
            0
        ));

        $questionturnoptions = [
            0 => '0',
            1 => '1',
            2 => '2',
            3 => '3',
        ];
        $settings->add(new $select(
            'local_forum_ai/default_question_turns',
            get_string('questionturns', 'local_forum_ai'),
            get_string('questionturns_help', 'local_forum_ai'),
            1,
            $questionturnoptions
        ));

        $settings->add(new $textarea(
            'local_forum_ai/default_reply_message',
            get_string('reply_message', 'local_forum_ai'),
            '',
            get_string('default_reply_message', 'local_forum_ai'),
            PARAM_RAW,
            3,
            3
        ));

        $settings->hide_if('local_forum_ai/default_enabled', 'local_forum_ai/enableforumai', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_enablediainitconversation', 'local_forum_ai/enableforumai', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_require_approval', 'local_forum_ai/enableforumai', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_usedelay', 'local_forum_ai/enableforumai', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_delayminutes', 'local_forum_ai/enableforumai', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_question_turns', 'local_forum_ai/enableforumai', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_replyinlocked', 'local_forum_ai/enableforumai', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_reply_message', 'local_forum_ai/enableforumai', 'eq', 0);

        $settings->hide_if('local_forum_ai/default_enablediainitconversation', 'local_forum_ai/default_enabled', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_require_approval', 'local_forum_ai/default_enabled', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_usedelay', 'local_forum_ai/default_enabled', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_delayminutes', 'local_forum_ai/default_enabled', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_question_turns', 'local_forum_ai/default_enabled', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_replyinlocked', 'local_forum_ai/default_enabled', 'eq', 0);
        $settings->hide_if('local_forum_ai/default_reply_message', 'local_forum_ai/default_enabled', 'eq', 0);

        $settings->hide_if('local_forum_ai/default_usedelay', 'local_forum_ai/default_require_approval', 'eq', 1);
        $settings->hide_if('local_forum_ai/default_delayminutes', 'local_forum_ai/default_require_approval', 'eq', 1);
        $settings->hide_if('local_forum_ai/default_delayminutes', 'local_forum_ai/default_usedelay', 'neq', 1);
    }

    $ADMIN->add($parentcategory, $settings);
}
