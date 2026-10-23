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
 * Tests for the tenant aware admin settings and their registration.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai\admin;

use local_forum_ai\local\tenant_config;

/**
 * Unit tests for the standard settings page that reads and writes the active tenant.
 *
 * @covers \local_forum_ai\admin\tenant_aware_setting
 * @covers \local_forum_ai\admin\tenant_configcheckbox
 * @covers \local_forum_ai\admin\tenant_configtext
 * @covers \local_forum_ai\admin\tenant_configtextarea
 * @covers \local_forum_ai\admin\tenant_configselect
 * @covers \local_forum_ai\admin\tenant_notice
 * @group local_forum_ai
 */
final class tenant_aware_setting_test extends \advanced_testcase {
    /** @var string Admin tree name of the settings page, the same one the LMS uses. */
    private const PAGE = 'local_forum_ai_settings';

    /**
     * Loads the admin library.
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        require_once($CFG->libdir . '/adminlib.php');
    }

    /**
     * Skips the test when Moodle Workplace tenancy is not installed.
     */
    private function require_tenancy(): void {
        if (!class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('Moodle Workplace (tool_tenant) is not installed.');
        }
    }

    /**
     * Creates a tenant through the Workplace generator.
     *
     * @param string $name Tenant name.
     * @return \stdClass The tenant record.
     */
    private function create_tenant(string $name): \stdClass {
        $category = $this->getDataGenerator()->create_category(['name' => $name . ' category']);
        return $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant([
            'name' => $name,
            'categoryid' => $category->id,
        ]);
    }

    /**
     * Returns the settings page registered in a freshly built admin tree.
     *
     * @return \admin_settingpage|null
     */
    private function load_page(): ?\admin_settingpage {
        $page = admin_get_root(true, true)->locate(self::PAGE);
        return $page instanceof \admin_settingpage ? $page : null;
    }

    /**
     * Returns one setting of the registered page.
     *
     * @param string $name Setting name without the plugin prefix.
     * @return \admin_setting
     */
    private function get_setting(string $name): \admin_setting {
        $page = $this->load_page();
        $this->assertNotNull($page);
        return $page->settings->{'local_forum_ai' . $name} ?? $this->fail('Missing setting ' . $name);
    }

    /**
     * Switches the site administrator to a tenant.
     *
     * @param int $tenantid Tenant id.
     */
    private function switch_to(int $tenantid): void {
        \tool_tenant\tenancy::set_switched_tenant_id($tenantid);
    }

    /**
     * Builds a text setting whose active tenant is forced, to reach the cases the switcher cannot produce.
     *
     * @param int $tenantid Active tenant id the setting should see.
     * @return tenant_configtext
     */
    private function setting_with_active_tenant(int $tenantid): tenant_configtext {
        return new class ($tenantid) extends tenant_configtext {
            /** @var int Forced tenant id. */
            private $forced;

            /**
             * Creates the setting.
             *
             * @param int $forced Forced tenant id.
             */
            public function __construct(int $forced) {
                $this->forced = $forced;
                parent::__construct('local_forum_ai/default_delayminutes', 'Delay', '', 60, PARAM_INT);
            }

            /**
             * Returns the forced tenant id.
             *
             * @return int
             */
            protected function get_active_tenant_id(): int {
                return $this->forced;
            }
        };
    }

    /**
     * Site administrators find the standard page under Local plugins, named after the plugin, and nowhere else.
     */
    public function test_page_is_listed_under_local_plugins_for_site_admins(): void {
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();

        $adminroot = admin_get_root(true, true);
        $page = $adminroot->locate(self::PAGE);

        $this->assertInstanceOf(\admin_settingpage::class, $page);
        $this->assertSame(get_string('pluginname', 'local_forum_ai'), (string) $page->visiblename);
        $this->assertNotEmpty($adminroot->locate('localplugins')->locate(self::PAGE));
        $this->assertEmpty($adminroot->locate('ai')->locate(self::PAGE));
        $this->assertSame(['local/forum_ai:managetenantsettings'], $page->req_capability);
        $this->assertStringEndsWith('/admin/settings.php?section=' . self::PAGE, $page->get_settings_page_url()->out(false));
    }

    /**
     * Tenant administrators cannot see Local plugins, so the same page is listed once under the AI category.
     */
    public function test_page_is_listed_under_ai_for_tenant_admins(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $tenant = $this->create_tenant('Tenant AI');
        $this->setUser($this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_user([
            'tenantid' => $tenant->id,
            'tenantadmin' => 1,
        ]));

        $adminroot = admin_get_root(true, true);
        $page = $adminroot->locate(self::PAGE);

        $this->assertInstanceOf(\admin_settingpage::class, $page);
        $this->assertNotEmpty($adminroot->locate('ai')->locate(self::PAGE));
        $this->assertEmpty($adminroot->locate('localplugins'));
        $this->assertTrue($page->check_access());
    }

    /**
     * A user without the capability gets no access to the page.
     */
    public function test_page_is_denied_to_plain_users(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $tenant = $this->create_tenant('Tenant plain');
        $this->setUser($this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_user(['tenantid' => $tenant->id]));

        $page = $this->load_page();

        $this->assertTrue($page === null || !$page->check_access());
    }

    /**
     * The page has the nine settings with the tenant aware types, in the LMS order, plus the tenant notice.
     */
    public function test_page_contains_the_tenant_aware_settings(): void {
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();

        $settings = $this->load_page()->settings;

        $this->assertInstanceOf(tenant_notice::class, $settings->local_forum_aitenantnotice);
        $checkboxes = [
            'enableforumai',
            'default_enabled',
            'default_enablediainitconversation',
            'default_require_approval',
            'default_usedelay',
            'default_replyinlocked',
        ];
        foreach ($checkboxes as $name) {
            $this->assertInstanceOf(tenant_configcheckbox::class, $settings->{'local_forum_ai' . $name});
        }
        $this->assertInstanceOf(tenant_configtext::class, $settings->local_forum_aidefault_delayminutes);
        $this->assertInstanceOf(tenant_configselect::class, $settings->local_forum_aidefault_question_turns);
        $this->assertInstanceOf(tenant_configtextarea::class, $settings->local_forum_aidefault_reply_message);
        foreach (tenant_config::SETTING_NAMES as $name) {
            $this->assertInstanceOf(\admin_setting::class, $settings->{'local_forum_ai' . $name});
        }
    }

    /**
     * The dependent settings are still hidden when the controlling switch is off.
     */
    public function test_hide_if_dependencies_are_kept(): void {
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();

        $property = new \ReflectionProperty(\admin_settingpage::class, 'dependencies');
        $property->setAccessible(true);
        $dependencies = $property->getValue($this->load_page());

        $this->assertCount(18, $dependencies);
    }

    /**
     * A tenant without stored values shows the plugin defaults, never the site configuration.
     */
    public function test_defaults_shown_come_from_the_plugin_not_the_site(): void {
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenant = $this->create_tenant('Tenant defaults');
        set_config('default_delayminutes', 5, 'local_forum_ai');
        set_config('default_reply_message', 'Site message', 'local_forum_ai');
        set_config('enableforumai', 0, 'local_forum_ai');
        $this->switch_to((int) $tenant->id);

        foreach (tenant_config::SETTING_NAMES as $name) {
            $this->assertEquals(tenant_config::get_plugin_default($name), $this->get_setting($name)->get_setting());
        }
    }

    /**
     * Values saved while one tenant is active are invisible to another one, and each tenant keeps its own.
     */
    public function test_values_are_isolated_per_active_tenant(): void {
        global $DB;

        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenanta = $this->create_tenant('Tenant A');
        $tenantb = $this->create_tenant('Tenant B');

        $siteconfig = get_config('local_forum_ai');
        $this->switch_to((int) $tenanta->id);
        $this->assertSame('', $this->get_setting('default_reply_message')->write_setting('Prompt of A'));
        $this->assertSame('', $this->get_setting('default_delayminutes')->write_setting('15'));
        $this->assertSame('', $this->get_setting('enableforumai')->write_setting('0'));

        $this->switch_to((int) $tenantb->id);
        $this->assertSame(
            tenant_config::get_plugin_default('default_reply_message'),
            $this->get_setting('default_reply_message')->get_setting()
        );
        $this->assertEquals(60, $this->get_setting('default_delayminutes')->get_setting());
        $this->assertEquals(1, $this->get_setting('enableforumai')->get_setting());
        $this->assertSame('', $this->get_setting('default_reply_message')->write_setting('Prompt of B'));

        $this->switch_to((int) $tenanta->id);
        $this->assertSame('Prompt of A', $this->get_setting('default_reply_message')->get_setting());
        $this->assertSame('15', $this->get_setting('default_delayminutes')->get_setting());
        $this->assertSame('0', $this->get_setting('enableforumai')->get_setting());

        $this->assertSame('Prompt of B', tenant_config::get('default_reply_message', (int) $tenantb->id));
        $this->assertFalse($DB->record_exists('local_forum_ai_tenant_config', ['tenantid' => 0]));
        $this->assertEquals($siteconfig, get_config('local_forum_ai'));
    }

    /**
     * The whole page save (admin_write_settings) stores the values of the active tenant only.
     */
    public function test_saving_the_page_writes_only_the_active_tenant(): void {
        global $DB;

        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenanta = $this->create_tenant('Tenant C');
        $tenantb = $this->create_tenant('Tenant D');
        $this->switch_to((int) $tenanta->id);

        $changed = admin_write_settings([
            's_local_forum_ai_enableforumai' => '1',
            's_local_forum_ai_default_enabled' => '1',
            's_local_forum_ai_default_require_approval' => '1',
            's_local_forum_ai_default_usedelay' => '1',
            's_local_forum_ai_default_delayminutes' => '30',
            's_local_forum_ai_default_reply_message' => 'Saved for C',
        ]);

        $this->assertGreaterThan(0, $changed);
        $this->assertSame('30', tenant_config::get('default_delayminutes', (int) $tenanta->id));
        $this->assertSame('Saved for C', tenant_config::get('default_reply_message', (int) $tenanta->id));
        $this->assertSame([], tenant_config::get_all((int) $tenantb->id));
        $this->assertFalse($DB->record_exists('local_forum_ai_tenant_config', ['tenantid' => 0]));
    }

    /**
     * The config log names the tenant, so the change is traceable.
     */
    public function test_config_log_records_the_tenant(): void {
        global $DB;

        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenant = $this->create_tenant('Tenant E');
        $this->switch_to((int) $tenant->id);

        $this->get_setting('default_reply_message')->write_setting('Logged prompt');

        $logname = 'tenant' . $tenant->id . '/default_reply_message';
        $log = $DB->get_record('config_log', ['plugin' => 'local_forum_ai', 'name' => $logname]);
        $this->assertNotFalse($log);
        $this->assertSame('Logged prompt', $log->value);
        $this->assertSame(tenant_config::get_plugin_default('default_reply_message'), $log->oldvalue);
    }

    /**
     * Without an active tenant a real save is refused with a message and nothing is stored.
     */
    public function test_saving_without_an_active_tenant_is_rejected(): void {
        global $DB;

        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();

        $siteconfig = get_config('local_forum_ai');
        $setting = $this->setting_with_active_tenant(0);
        $error = $setting->write_setting('15');

        $this->assertSame(get_string('notenantselected', 'local_forum_ai'), $error);
        $this->assertSame(0, $DB->count_records('local_forum_ai_tenant_config'));
        $this->assertEquals($siteconfig, get_config('local_forum_ai'));
    }

    /**
     * Without an active tenant the page shows the plugin defaults, never the site value.
     */
    public function test_reading_without_an_active_tenant_returns_plugin_defaults(): void {
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('default_delayminutes', 5, 'local_forum_ai');

        $this->assertEquals(60, $this->setting_with_active_tenant(0)->get_setting());
    }

    /**
     * A user who cannot manage the active tenant cannot save its values.
     */
    public function test_saving_without_the_capability_is_rejected(): void {
        global $DB;

        $this->require_tenancy();
        $this->resetAfterTest();
        $tenant = $this->create_tenant('Tenant F');
        $this->setUser($this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_user(['tenantid' => $tenant->id]));

        $error = $this->setting_with_active_tenant((int) $tenant->id)->write_setting('15');

        $this->assertNotSame('', $error);
        $this->assertSame(0, $DB->count_records('local_forum_ai_tenant_config'));
    }

    /**
     * A tenant administrator edits only the own tenant: the active tenant cannot be chosen from the request.
     */
    public function test_tenant_admin_writes_only_the_own_tenant(): void {
        $this->require_tenancy();
        $this->resetAfterTest();
        $tenanta = $this->create_tenant('Tenant G');
        $tenantb = $this->create_tenant('Tenant H');
        $this->setUser($this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_user([
            'tenantid' => $tenanta->id,
            'tenantadmin' => 1,
        ]));

        $this->assertSame('', $this->get_setting('default_delayminutes')->write_setting('20'));

        $this->assertSame('20', tenant_config::get('default_delayminutes', (int) $tenanta->id));
        $this->assertSame([], tenant_config::get_all((int) $tenantb->id));
    }

    /**
     * Core applies every default on install: that must not write any tenant row nor touch a tenant value.
     */
    public function test_applying_default_settings_does_not_write_tenant_rows(): void {
        global $DB;

        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenant = $this->create_tenant('Tenant I');
        $this->switch_to((int) $tenant->id);
        tenant_config::set('default_reply_message', (int) $tenant->id, 'Prompt kept');
        tenant_config::set('enableforumai', (int) $tenant->id, 0);
        $before = tenant_config::get_all((int) $tenant->id);
        $rowsbefore = $DB->count_records('local_forum_ai_tenant_config');

        admin_apply_default_settings(null, true);

        $this->assertSame($before, tenant_config::get_all((int) $tenant->id));
        $this->assertSame($rowsbefore, $DB->count_records('local_forum_ai_tenant_config'));
        $this->assertFalse($DB->record_exists('local_forum_ai_tenant_config', ['tenantid' => 0]));
    }

    /**
     * The notice names the tenant being edited.
     */
    public function test_notice_names_the_active_tenant(): void {
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenant = $this->create_tenant('Tenant Visible');
        $this->switch_to((int) $tenant->id);

        $html = $this->get_setting('tenantnotice')->output_html('');

        $this->assertStringContainsString('Tenant Visible', $html);
        $this->assertStringContainsString(get_string('editingtenant', 'local_forum_ai', 'Tenant Visible'), strip_tags($html));
    }

    /**
     * Without an active tenant the notice is a clear warning instead of a tenant name.
     */
    public function test_notice_warns_when_there_is_no_active_tenant(): void {
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();

        $notice = new class ('local_forum_ai/tenantnotice') extends tenant_notice {
            /**
             * Returns no tenant.
             *
             * @return int
             */
            protected function get_active_tenant_id(): int {
                return 0;
            }
        };

        $this->assertStringContainsString(get_string('notenantselected', 'local_forum_ai'), strip_tags($notice->output_html('')));
    }

    /**
     * Without Workplace the page is the plain LMS one: standard setting types reading the site configuration.
     */
    public function test_lms_page_uses_standard_settings_and_site_config(): void {
        if (class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('Only meaningful on a site without Workplace.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();

        $page = $this->load_page();

        $this->assertNotNull($page);
        $this->assertNotEmpty(admin_get_root(true, true)->locate('localplugins')->locate(self::PAGE));
        foreach (tenant_config::SETTING_NAMES as $name) {
            $setting = $page->settings->{'local_forum_ai' . $name};
            $this->assertNotContains(tenant_aware_setting::class, class_uses($setting));
        }
        $this->assertSame('', $page->settings->local_forum_aidefault_reply_message->write_setting('LMS prompt'));
        $this->assertSame('LMS prompt', get_config('local_forum_ai', 'default_reply_message'));
    }

    /**
     * The tenant aware types fall back to the site configuration when tenancy is not available.
     */
    public function test_tenant_aware_types_use_site_config_without_tenancy(): void {
        if (class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('Only meaningful on a site without Workplace.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();

        $setting = new tenant_configtext('local_forum_ai/default_delayminutes', 'Delay', '', 60, PARAM_INT);

        $this->assertSame('', $setting->write_setting('25'));
        $this->assertSame('25', get_config('local_forum_ai', 'default_delayminutes'));
        $this->assertSame('25', $setting->get_setting());
    }

    /**
     * The question turns select stores its value for the active tenant only.
     */
    public function test_select_is_stored_per_tenant(): void {
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenanta = $this->create_tenant('Tenant Select A');
        $tenantb = $this->create_tenant('Tenant Select B');

        $this->switch_to((int) $tenanta->id);
        $this->assertSame('', $this->get_setting('default_question_turns')->write_setting('3'));

        $this->assertSame('3', tenant_config::get('default_question_turns', (int) $tenanta->id));
        $this->assertEquals(1, tenant_config::get('default_question_turns', (int) $tenantb->id));
        $this->switch_to((int) $tenantb->id);
        $this->assertEquals(1, $this->get_setting('default_question_turns')->get_setting());
    }

    /**
     * Turning the default AI switch off while a tenant is active disables only the forums of that tenant.
     */
    public function test_saving_default_enabled_off_disables_only_the_forums_of_the_active_tenant(): void {
        global $DB;

        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenanta = $this->create_tenant('Tenant Disable A');
        $tenantb = $this->create_tenant('Tenant Disable B');
        $forumids = [];
        foreach ([$tenanta, $tenantb] as $tenant) {
            $course = $this->getDataGenerator()->create_course(['category' => $tenant->categoryid]);
            $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
            $DB->insert_record('local_forum_ai_config', (object) [
                'forumid' => $forum->id,
                'enabled' => 1,
                'require_approval' => 1,
                'reply_message' => 'm',
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
            $forumids[] = (int) $forum->id;
        }
        $this->switch_to((int) $tenanta->id);

        admin_write_settings(['s_local_forum_ai_default_enabled' => '0']);

        $this->assertSame('0', tenant_config::get('default_enabled', (int) $tenanta->id));
        $this->assertEquals(0, $DB->get_field('local_forum_ai_config', 'enabled', ['forumid' => $forumids[0]]));
        $this->assertEquals(1, $DB->get_field('local_forum_ai_config', 'enabled', ['forumid' => $forumids[1]]));
        $this->assertEquals(1, tenant_config::get('default_enabled', (int) $tenantb->id));
    }

    /**
     * Without an active tenant the callback of the default AI switch never disables the forums of the site.
     */
    public function test_updated_callback_does_nothing_without_an_active_tenant(): void {
        global $DB;

        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $DB->insert_record('local_forum_ai_config', (object) [
            'forumid' => $forum->id,
            'enabled' => 1,
            'require_approval' => 1,
            'reply_message' => 'm',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Without an active tenant nothing is saved and nothing is disabled.
        $setting = $this->get_setting('default_enabled');
        $setting->write_setting('0');

        $this->assertEquals(1, $DB->get_field('local_forum_ai_config', 'enabled', ['forumid' => $forum->id]));
    }
}
