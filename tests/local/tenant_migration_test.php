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

namespace local_forum_ai\local;

/**
 * Tests for the migration of the former site settings to the Workplace default tenant.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group     local_forum_ai
 * @covers    \local_forum_ai\local\tenant_migration
 */
final class tenant_migration_test extends \advanced_testcase {
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
     * Site values customised before the upgrade.
     */
    private function set_custom_site_config(): void {
        set_config('enableforumai', 0, 'local_forum_ai');
        set_config('default_reply_message', 'Site reply message', 'local_forum_ai');
        set_config('default_delayminutes', 15, 'local_forum_ai');
    }

    /**
     * The site values become the values of the default tenant; another tenant keeps the plugin defaults.
     */
    public function test_site_values_are_moved_to_the_default_tenant_only(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $this->set_custom_site_config();
        $other = $this->create_tenant('Other tenant');
        $defaulttenantid = \tool_tenant\tenancy::get_default_tenant_id();

        tenant_migration::migrate_site_data_to_default_tenant();

        $this->assertSame('0', tenant_config::get('enableforumai', $defaulttenantid));
        $this->assertSame('Site reply message', tenant_config::get('default_reply_message', $defaulttenantid));
        $this->assertSame('15', tenant_config::get('default_delayminutes', $defaulttenantid));

        $this->assertSame([], tenant_config::get_all((int) $other->id));
        $this->assertSame(1, tenant_config::get('enableforumai', (int) $other->id));
        $this->assertSame(60, tenant_config::get('default_delayminutes', (int) $other->id));
        $this->assertSame(
            get_string('default_reply_message', 'local_forum_ai'),
            tenant_config::get('default_reply_message', (int) $other->id)
        );
    }

    /**
     * The migrated site rows are removed from config_plugins, so no value remains outside a real tenant.
     */
    public function test_site_rows_are_removed_after_the_migration(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $this->set_custom_site_config();
        set_config('unrelatedsetting', 'kept', 'local_forum_ai');

        tenant_migration::migrate_site_data_to_default_tenant();

        foreach (tenant_config::SETTING_NAMES as $name) {
            $this->assertFalse(get_config('local_forum_ai', $name), $name);
        }
        $this->assertSame('kept', get_config('local_forum_ai', 'unrelatedsetting'));
    }

    /**
     * Running the migration twice leaves the default tenant exactly as after the first run.
     */
    public function test_migration_is_idempotent(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $this->set_custom_site_config();
        $defaulttenantid = \tool_tenant\tenancy::get_default_tenant_id();

        tenant_migration::migrate_site_data_to_default_tenant();
        $first = tenant_config::get_all($defaulttenantid);
        tenant_migration::migrate_site_data_to_default_tenant();

        $this->assertSame($first, tenant_config::get_all($defaulttenantid));
        $this->assertSame('0', tenant_config::get('enableforumai', $defaulttenantid));
        $this->assertSame('Site reply message', tenant_config::get('default_reply_message', $defaulttenantid));
    }

    /**
     * A value the default tenant already stores wins over the site value.
     */
    public function test_existing_default_tenant_value_is_not_overwritten(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $this->set_custom_site_config();
        $defaulttenantid = \tool_tenant\tenancy::get_default_tenant_id();
        tenant_config::set('default_delayminutes', $defaulttenantid, 45);

        tenant_migration::migrate_site_data_to_default_tenant();

        $this->assertSame('45', tenant_config::get('default_delayminutes', $defaulttenantid));
        $this->assertSame('Site reply message', tenant_config::get('default_reply_message', $defaulttenantid));
    }

    /**
     * On a plain Moodle LMS the site configuration is the live configuration and must stay untouched.
     */
    public function test_migration_does_nothing_without_tenancy(): void {
        global $DB;

        if (class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('Only meaningful on a site without Workplace.');
        }
        $this->resetAfterTest();

        $this->set_custom_site_config();
        $siteconfig = get_config('local_forum_ai');

        tenant_migration::migrate_site_data_to_default_tenant();

        $this->assertEquals($siteconfig, get_config('local_forum_ai'));
        $this->assertSame(0, $DB->count_records('local_forum_ai_tenant_config'));
    }
}
