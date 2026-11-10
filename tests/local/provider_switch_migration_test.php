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

use aiprovider_datacurso\local\plugin_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/forum_ai/db/upgrade.php');

/**
 * Tests that the former "Enable Forum AI" switch is moved into the Datacurso AI provider on upgrade.
 *
 * @package    local_forum_ai
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forum_ai\local\provider_switch_migration
 */
final class provider_switch_migration_test extends \advanced_testcase {
    /**
     * Skips every test when the Datacurso AI provider is not installed.
     */
    protected function setUp(): void {
        parent::setUp();
        // The CI pipeline does not install declared dependencies; without the Datacurso AI provider these tests cannot run.
        if (!class_exists(plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
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
     * Creates a Workplace tenant.
     *
     * @param string $name Tenant name.
     * @return int Tenant id.
     */
    private function create_tenant(string $name): int {
        $category = $this->getDataGenerator()->create_category(['name' => $name . ' category']);
        $tenant = $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant([
            'name' => $name,
            'categoryid' => $category->id,
        ]);
        return (int) $tenant->id;
    }

    /**
     * Stores a former "Enable Forum AI" value for a tenant, as the previous versions did.
     *
     * @param int $tenantid Tenant id.
     * @param string $value Stored value.
     */
    private function store_former_tenant_value(int $tenantid, string $value): void {
        global $DB;

        $DB->insert_record('local_forum_ai_tenant_config', (object) [
            'tenantid' => $tenantid,
            'name' => 'enableforumai',
            'value' => $value,
            'timemodified' => time(),
        ]);
    }

    /**
     * The upgrade copies each tenant value into the provider for that tenant and removes the former values.
     */
    public function test_upgrade_copies_tenant_values_to_the_provider(): void {
        global $DB;

        $this->require_tenancy();
        $this->resetAfterTest();

        $tenanta = $this->create_tenant('Migration tenant A');
        $tenantb = $this->create_tenant('Migration tenant B');
        $tenantc = $this->create_tenant('Migration tenant C');
        $this->store_former_tenant_value($tenanta, '0');
        $this->store_former_tenant_value($tenantb, '1');

        set_config('version', 2026110900, 'local_forum_ai');
        xmldb_local_forum_ai_upgrade(2026110900);

        $this->assertFalse(plugin_settings::is_enabled('local_forum_ai', $tenanta));
        $this->assertTrue(plugin_settings::is_enabled('local_forum_ai', $tenantb));
        $this->assertTrue(plugin_settings::is_enabled('local_forum_ai', $tenantc));
        $this->assertFalse($DB->record_exists('local_forum_ai_tenant_config', ['name' => 'enableforumai']));
    }

    /**
     * A value the provider already holds for a tenant is never overwritten by the former value.
     */
    public function test_existing_provider_tenant_value_is_kept(): void {
        global $DB;

        $this->require_tenancy();
        $this->resetAfterTest();

        $tenantid = $this->create_tenant('Migration tenant kept');
        plugin_settings::set_enabled('local_forum_ai', true, $tenantid);
        $this->store_former_tenant_value($tenantid, '0');

        provider_switch_migration::migrate();

        $this->assertTrue(plugin_settings::is_enabled('local_forum_ai', $tenantid));
        $this->assertFalse($DB->record_exists('local_forum_ai_tenant_config', ['name' => 'enableforumai']));
    }

    /**
     * A disabled site value becomes the provider site default and is removed from the plugin configuration.
     */
    public function test_site_value_is_copied_to_the_provider_site_default(): void {
        $this->resetAfterTest();

        set_config('enableforumai', 0, 'local_forum_ai');

        provider_switch_migration::migrate();

        $this->assertFalse(plugin_settings::is_enabled('local_forum_ai'));
        $this->assertFalse(get_config('local_forum_ai', 'enableforumai'));
    }

    /**
     * A site value never overwrites the provider site default and running the migration again changes nothing.
     */
    public function test_existing_provider_site_value_is_kept_and_migration_is_idempotent(): void {
        $this->resetAfterTest();

        plugin_settings::set_enabled('local_forum_ai', true);
        set_config('enableforumai', 0, 'local_forum_ai');

        provider_switch_migration::migrate();
        provider_switch_migration::migrate();

        $this->assertTrue(plugin_settings::is_enabled('local_forum_ai'));
        $this->assertFalse(get_config('local_forum_ai', 'enableforumai'));
    }
}
