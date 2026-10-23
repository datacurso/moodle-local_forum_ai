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
 * Moves the former site wide plugin settings to the Workplace default tenant.
 *
 * Before every tenant became fully independent, the plugin settings lived in config_plugins. The upgrade hands them
 * to the default tenant, so the site keeps behaving as before for its users, and nothing remains outside a real
 * tenant. On a plain Moodle LMS config_plugins stays the live configuration and nothing is migrated.
 *
 * @package     local_forum_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_migration {
    /** @var string Plugin whose config_plugins rows are migrated. */
    private const PLUGIN = 'local_forum_ai';

    /**
     * Moves the site settings to the default tenant.
     *
     * A value the default tenant already stores wins over the site value; the site value is removed from
     * config_plugins either way. Safe to run more than once. Does nothing when tenancy is not available.
     *
     * @return void
     */
    public static function migrate_site_data_to_default_tenant(): void {
        if (!tenant_context::is_tenancy_available()) {
            return;
        }

        $tenantid = (int) \tool_tenant\tenancy::get_default_tenant_id();
        if ($tenantid <= 0) {
            return;
        }

        $stored = tenant_config::get_all($tenantid);
        foreach (get_config(self::PLUGIN) as $name => $value) {
            $name = (string) $name;
            if (!in_array($name, tenant_config::SETTING_NAMES, true)) {
                continue;
            }
            if (!array_key_exists($name, $stored)) {
                tenant_config::set($name, $tenantid, $value);
            }
            unset_config($name, self::PLUGIN);
        }
    }
}
