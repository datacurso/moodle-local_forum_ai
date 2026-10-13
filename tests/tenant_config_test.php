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
 * Tests for tenant resolution and tenant scoped configuration.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

use local_forum_ai\local\tenant_config;
use local_forum_ai\local\tenant_context;

/**
 * Unit tests for tenant_context and tenant_config.
 *
 * @covers \local_forum_ai\local\tenant_context
 * @covers \local_forum_ai\local\tenant_config
 * @group local_forum_ai
 */
final class tenant_config_test extends \advanced_testcase {
    /**
     * Skips the test when Moodle Workplace tenancy is not installed.
     */
    private function require_tenancy(): void {
        if (!class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('Moodle Workplace (tool_tenant) is not installed.');
        }
    }

    /**
     * Skips the test when Moodle Workplace tenancy is installed.
     */
    private function require_no_tenancy(): void {
        if (class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('Only meaningful on a site without Workplace.');
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
     * Site values that differ from every plugin default.
     */
    private function set_divergent_site_config(): void {
        set_config('enableforumai', 0, 'local_forum_ai');
        set_config('default_enabled', 0, 'local_forum_ai');
        set_config('default_enablediainitconversation', 1, 'local_forum_ai');
        set_config('default_require_approval', 0, 'local_forum_ai');
        set_config('default_usedelay', 1, 'local_forum_ai');
        set_config('default_delayminutes', 5, 'local_forum_ai');
        set_config('default_replyinlocked', 1, 'local_forum_ai');
        set_config('default_question_turns', 3, 'local_forum_ai');
        set_config('default_reply_message', 'Site message', 'local_forum_ai');
    }

    /**
     * Setting names mapped to the plugin built-in default they must resolve to.
     *
     * @return array
     */
    public static function plugin_defaults_provider(): array {
        return [
            'enableforumai' => ['enableforumai', 1],
            'default_enabled' => ['default_enabled', 1],
            'default_enablediainitconversation' => ['default_enablediainitconversation', 0],
            'default_require_approval' => ['default_require_approval', 1],
            'default_usedelay' => ['default_usedelay', 0],
            'default_delayminutes' => ['default_delayminutes', 60],
            'default_replyinlocked' => ['default_replyinlocked', 0],
            'default_question_turns' => ['default_question_turns', 1],
        ];
    }

    /**
     * A tenant without stored rows gets the plugin default, whatever the site configuration holds.
     *
     * @dataProvider plugin_defaults_provider
     * @param string $name Setting name.
     * @param int $expected Plugin built-in default.
     */
    public function test_tenant_without_rows_uses_plugin_default_not_site_config(string $name, int $expected): void {
        $this->resetAfterTest();
        $this->set_divergent_site_config();

        $this->assertEquals($expected, tenant_config::get($name, 77));
    }

    /**
     * The default reply message of a tenant without rows is the plugin text, not the site text.
     */
    public function test_tenant_without_rows_uses_plugin_default_reply_message(): void {
        $this->resetAfterTest();
        $this->set_divergent_site_config();

        $this->assertSame(
            get_string('default_reply_message', 'local_forum_ai'),
            tenant_config::get('default_reply_message', 77)
        );
    }

    /**
     * Unknown names keep returning the caller supplied default.
     */
    public function test_unknown_name_returns_caller_default(): void {
        $this->resetAfterTest();

        $this->assertFalse(tenant_config::get('doesnotexist', 77));
        $this->assertSame('x', tenant_config::get('doesnotexist', 77, 'x'));
    }

    /**
     * Changing the site configuration never alters the values a tenant resolves.
     */
    public function test_site_config_changes_do_not_alter_a_tenant(): void {
        $this->resetAfterTest();

        tenant_config::set('default_delayminutes', 5, 20);
        $before = [tenant_config::get('default_delayminutes', 5), tenant_config::get('default_delayminutes', 6)];

        $this->set_divergent_site_config();

        $this->assertEquals($before[0], tenant_config::get('default_delayminutes', 5));
        $this->assertEquals($before[1], tenant_config::get('default_delayminutes', 6));
        $this->assertEquals(60, tenant_config::get('default_delayminutes', 6));
    }

    /**
     * Without tenancy, tenant 0 reads the site wide plugin config as before.
     */
    public function test_tenant_zero_reads_site_config_without_tenancy(): void {
        $this->require_no_tenancy();
        $this->resetAfterTest();

        set_config('default_reply_message', 'Site message', 'local_forum_ai');
        set_config('default_delayminutes', 5, 'local_forum_ai');

        $this->assertSame('Site message', tenant_config::get('default_reply_message', 0));
        $this->assertEquals(5, tenant_config::get('default_delayminutes', 0));
    }

    /**
     * Without tenancy and without a site value the caller default is returned.
     */
    public function test_tenant_zero_without_site_value_returns_caller_default_without_tenancy(): void {
        $this->require_no_tenancy();
        $this->resetAfterTest();

        unset_config('default_delayminutes', 'local_forum_ai');

        $this->assertSame(30, tenant_config::get('default_delayminutes', 0, 30));
    }

    /**
     * Stored tenant values override the plugin default and are isolated per tenant.
     */
    public function test_set_and_get_per_tenant(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('default_reply_message', 'Site message', 'local_forum_ai');

        tenant_config::set('default_reply_message', 5, 'Tenant five');
        tenant_config::set('default_reply_message', 6, 'Tenant six');

        $this->assertSame('Tenant five', tenant_config::get('default_reply_message', 5));
        $this->assertSame('Tenant six', tenant_config::get('default_reply_message', 6));
        $this->assertSame(
            get_string('default_reply_message', 'local_forum_ai'),
            tenant_config::get('default_reply_message', 7)
        );

        tenant_config::set('default_reply_message', 5, 'Updated');
        $this->assertSame('Updated', tenant_config::get('default_reply_message', 5));
        $this->assertSame(2, $DB->count_records('local_forum_ai_tenant_config', ['name' => 'default_reply_message']));
        $this->assertSame(['default_reply_message' => 'Updated'], tenant_config::get_all(5));
    }

    /**
     * A stored zero is a real value, not a missing one.
     */
    public function test_stored_zero_overrides_the_default(): void {
        $this->resetAfterTest();

        tenant_config::set('enableforumai', 5, 0);

        $this->assertSame('0', tenant_config::get('enableforumai', 5));
        $this->assertEquals(1, tenant_config::get('enableforumai', 6));
    }

    /**
     * Tenant 0 is the implicit site tenant and never writes to the tenant table.
     */
    public function test_set_for_tenant_zero_is_rejected(): void {
        $this->resetAfterTest();

        $this->expectException(\coding_exception::class);
        tenant_config::set('default_enabled', 0, 1);
    }

    /**
     * Only the supported setting names can be stored.
     */
    public function test_set_rejects_unknown_names(): void {
        $this->resetAfterTest();

        $this->expectException(\coding_exception::class);
        tenant_config::set('licensekey', 3, 'x');
    }

    /**
     * Deleting the tenant values restores the plugin defaults, never the site value.
     */
    public function test_delete_all_restores_plugin_defaults(): void {
        $this->resetAfterTest();

        set_config('default_delayminutes', 30, 'local_forum_ai');
        tenant_config::set('default_delayminutes', 3, 15);
        tenant_config::set('default_delayminutes', 4, 25);
        $this->assertEquals(15, tenant_config::get('default_delayminutes', 3));

        tenant_config::delete_all(3);

        $this->assertEquals(60, tenant_config::get('default_delayminutes', 3));
        $this->assertEquals(25, tenant_config::get('default_delayminutes', 4));
    }

    /**
     * The tenant resolver returns 0 when tenancy is unavailable.
     */
    public function test_resolver_returns_zero_without_tenancy(): void {
        $this->require_no_tenancy();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(tenant_context::is_tenancy_available());
        $this->assertSame(0, tenant_context::get_tenant_id_for_course((int) $course->id));
        $this->assertSame(0, tenant_context::get_tenant_id_for_user((int) $user->id));
        $this->assertSame(0, tenant_context::resolve((int) $course->id, (int) $user->id));
        $this->assertSame([], tenant_context::get_manageable_tenants((int) $user->id));
        $this->assertSame('', tenant_context::get_tenant_name(3));
    }

    /**
     * A course inside a tenant category (or a sub category of it) resolves to that tenant.
     */
    public function test_resolver_walks_course_category_parents(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $tenant = $this->create_tenant('Tenant A');
        $sub = $this->getDataGenerator()->create_category(['parent' => $tenant->categoryid]);
        $subsub = $this->getDataGenerator()->create_category(['parent' => $sub->id]);

        $direct = $this->getDataGenerator()->create_course(['category' => $tenant->categoryid]);
        $nested = $this->getDataGenerator()->create_course(['category' => $subsub->id]);

        $this->assertSame((int) $tenant->id, tenant_context::get_tenant_id_for_course((int) $direct->id));
        $this->assertSame((int) $tenant->id, tenant_context::get_tenant_id_for_course((int) $nested->id));
    }

    /**
     * The tenant of a forum is the tenant of its course.
     */
    public function test_resolver_finds_the_tenant_of_a_forum(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $tenant = $this->create_tenant('Tenant Forum');
        $course = $this->getDataGenerator()->create_course(['category' => $tenant->categoryid]);
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $this->assertSame((int) $tenant->id, tenant_context::get_tenant_id_for_forum((int) $forum->id));
        $this->assertSame(0, tenant_context::get_tenant_id_for_forum(0));
    }

    /**
     * A course outside any tenant category falls back to the tenant of the user.
     */
    public function test_resolver_falls_back_to_user_tenant(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $tenant = $this->create_tenant('Tenant B');
        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $user = $generator->create_user(['tenantid' => $tenant->id]);
        $sharedcourse = $this->getDataGenerator()->create_course();

        $this->assertSame(0, tenant_context::get_tenant_id_for_course((int) $sharedcourse->id));
        $this->assertSame((int) $tenant->id, tenant_context::get_tenant_id_for_user((int) $user->id));
        $this->assertSame((int) $tenant->id, tenant_context::resolve((int) $sharedcourse->id, (int) $user->id));
    }

    /**
     * The course tenant wins over the tenant of the user.
     */
    public function test_resolver_prefers_course_tenant(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $tenanta = $this->create_tenant('Tenant C');
        $tenantb = $this->create_tenant('Tenant D');
        $user = $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_user(['tenantid' => $tenantb->id]);
        $course = $this->getDataGenerator()->create_course(['category' => $tenanta->categoryid]);

        $this->assertSame((int) $tenanta->id, tenant_context::resolve((int) $course->id, (int) $user->id));
    }

    /**
     * Under Workplace, tenant 0 resolves to the plugin defaults and never to the site configuration.
     */
    public function test_tenant_zero_uses_plugin_defaults_under_tenancy(): void {
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->set_divergent_site_config();

        foreach (array_keys(self::plugin_defaults_provider()) as $name) {
            $this->assertEquals(tenant_config::get_plugin_default($name), tenant_config::get($name, 0));
        }
        $this->assertSame(
            get_string('default_reply_message', 'local_forum_ai'),
            tenant_config::get('default_reply_message', 0)
        );
    }

    /**
     * Tenant administrators manage only their own tenant; site administrators may manage any tenant.
     */
    public function test_can_manage_tenant_settings(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $tenanta = $this->create_tenant('Tenant I');
        $tenantb = $this->create_tenant('Tenant J');
        $tenantadmin = $generator->create_user(['tenantid' => $tenanta->id, 'tenantadmin' => 1]);
        $plainuser = $generator->create_user(['tenantid' => $tenanta->id]);
        $admin = get_admin();

        $this->assertTrue(tenant_context::can_manage_tenant_settings((int) $tenanta->id, (int) $tenantadmin->id));
        $this->assertFalse(tenant_context::can_manage_tenant_settings((int) $tenantb->id, (int) $tenantadmin->id));
        $this->assertFalse(tenant_context::can_manage_tenant_settings((int) $tenanta->id, (int) $plainuser->id));
        $this->assertTrue(tenant_context::can_manage_tenant_settings((int) $tenantb->id, (int) $admin->id));
    }
}
