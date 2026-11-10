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

/**
 * Moves the former "Enable Forum AI" switch (enableforumai) into the Datacurso AI provider.
 *
 * Forum AI is now switched on and off, per tenant, only from the provider. The values the plugin stored (per tenant
 * in {local_forum_ai_tenant_config}, or at site level in config_plugins) are copied into the provider and then
 * removed. A value the provider already holds for a tenant (or for the site) is never overwritten.
 *
 * @package     local_forum_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_switch_migration {
    /** @var string Plugin component. */
    private const COMPONENT = 'local_forum_ai';

    /** @var string Former setting name. */
    private const SETTING = 'enableforumai';

    /** @var string Table of the tenant values. */
    private const TABLE = 'local_forum_ai_tenant_config';

    /** @var string Provider class that keeps the tenant values. */
    private const PROVIDER_TENANT_CONFIG = '\aiprovider_datacurso\local\tenant_config';

    /**
     * Copies the former switch values into the provider and removes them from the plugin.
     *
     * Does nothing when the provider is not installed, so the values are not lost. Safe to run more than once.
     *
     * @return void
     */
    public static function migrate(): void {
        global $DB;

        if (!class_exists(plugin_settings::class)) {
            return;
        }

        $dbman = $DB->get_manager();
        if ($dbman->table_exists(self::TABLE)) {
            $rows = $DB->get_records(self::TABLE, ['name' => self::SETTING], 'tenantid ASC', 'id, tenantid, value');
            foreach ($rows as $row) {
                self::copy_value((string) $row->value, (int) $row->tenantid);
            }
            $DB->delete_records(self::TABLE, ['name' => self::SETTING]);
        }

        $sitevalue = get_config(self::COMPONENT, self::SETTING);
        if ($sitevalue !== false) {
            self::copy_value((string) $sitevalue, null);
        }
        unset_config(self::SETTING, self::COMPONENT);
    }

    /**
     * Copies one former value into the provider unless the provider already holds a value for that scope.
     *
     * An empty value meant "enabled" (the former default), which is also the provider default, so nothing is copied.
     * When the provider cannot tell whether a tenant value exists, only a disabled value is copied: enabled is the
     * provider default, and a disabled tenant must stay disabled.
     *
     * @param string $value Former value.
     * @param int|null $tenantid Tenant id, null for the site level value.
     * @return void
     */
    private static function copy_value(string $value, ?int $tenantid): void {
        if ($value === '' || ($tenantid !== null && $tenantid <= 0)) {
            return;
        }

        $enabled = !empty($value);
        $stored = self::provider_has_value($tenantid);
        if ($stored === true || ($stored === null && $enabled)) {
            return;
        }

        plugin_settings::set_enabled(self::COMPONENT, $enabled, $tenantid);
    }

    /**
     * Tells whether the provider already holds a value of Forum AI for a tenant or for the site.
     *
     * @param int|null $tenantid Tenant id, null for the site level value.
     * @return bool|null True or false, or null when the provider cannot tell.
     */
    private static function provider_has_value(?int $tenantid): ?bool {
        if ($tenantid === null) {
            $value = get_config('aiprovider_datacurso', self::provider_key());
            return $value !== false && $value !== '';
        }

        $class = self::PROVIDER_TENANT_CONFIG;
        if (!class_exists($class) || !method_exists($class, 'get_stored')) {
            return null;
        }

        // Only the tenant's own row counts; get() would fall back to the site value.
        $value = $class::get_stored('aiprovider_datacurso', $tenantid, self::provider_key());
        return $value !== null && $value !== '';
    }

    /**
     * Returns the provider key of the Forum AI switch.
     *
     * @return string
     */
    private static function provider_key(): string {
        $prefix = defined(plugin_settings::class . '::CONFIG_PREFIX') ? plugin_settings::CONFIG_PREFIX : 'plugin_enabled_';
        return $prefix . self::COMPONENT;
    }
}
