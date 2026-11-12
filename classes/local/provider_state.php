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
 * Whether the Datacurso AI provider lets Forum AI run.
 *
 * The footer hook, the navigation and form callbacks of lib.php and the event observers run on
 * every page, including the upgrade pages that report a missing dependency. When the code of
 * Forum AI is deployed before the provider that keeps its state (aiprovider_datacurso 1.7.0,
 * 2026110400), the provider class does not exist yet: Forum AI then stays out of the page instead
 * of breaking it, so the administrator can still reach the upgrade.
 *
 * On Moodle Workplace the provider keeps one state per tenant: the tenant is the one of the acting
 * user (or the one given by the caller), never the tenant of the course.
 *
 * Same approach as local_dttutor\local\provider_state.
 *
 * @package    local_forum_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_state {
    /** @var string Class of the provider that keeps the state of each suite plugin. */
    public const SETTINGS_CLASS = '\\aiprovider_datacurso\\local\\plugin_settings';

    /** @var string Component whose state is read. */
    private const COMPONENT = 'local_forum_ai';

    /**
     * Whether Forum AI is enabled in the Datacurso AI provider.
     *
     * @param int|null $tenantid Tenant whose state is read, null for the tenant of the current user.
     * @param string $settingsclass Class that keeps the plugin state; only tests name another one.
     * @return bool False when the provider is too old to keep the state, or Forum AI is disabled there.
     */
    public static function is_enabled(?int $tenantid = null, string $settingsclass = self::SETTINGS_CLASS): bool {
        if (!class_exists($settingsclass)) {
            return false;
        }
        return $settingsclass::is_enabled(self::COMPONENT, $tenantid);
    }

    /**
     * Refuse the current action unless Forum AI is enabled in the Datacurso AI provider.
     *
     * @param int|null $tenantid Tenant whose state is read, null for the tenant of the current user.
     * @param string $settingsclass Class that keeps the plugin state; only tests name another one.
     * @return void
     * @throws \moodle_exception error_providerupgraderequired when the provider is too old to keep
     *                           the state, or the provider's plugindisabled message when Forum AI
     *                           is disabled there.
     */
    public static function require_enabled(?int $tenantid = null, string $settingsclass = self::SETTINGS_CLASS): void {
        if (!class_exists($settingsclass)) {
            throw new \moodle_exception('error_providerupgraderequired', self::COMPONENT);
        }
        $settingsclass::require_enabled(self::COMPONENT, $tenantid);
    }

    /**
     * Whether the Datacurso AI provider is recent enough to keep the state of Forum AI.
     *
     * @param string $settingsclass Class that keeps the plugin state; only tests name another one.
     * @return bool
     */
    public static function is_available(string $settingsclass = self::SETTINGS_CLASS): bool {
        return class_exists($settingsclass);
    }

    /**
     * Enable or disable Forum AI in the Datacurso AI provider, for a tenant or for the site.
     *
     * Used by the upgrade that moves the former "Enable Forum AI" values into the provider.
     *
     * @param bool $enabled New state.
     * @param int|null $tenantid Tenant to write the state for; null writes the site default.
     * @param string $settingsclass Class that keeps the plugin state; only tests name another one.
     * @return void
     * @throws \moodle_exception error_providerupgraderequired when the provider is too old to keep the state.
     */
    public static function set_enabled(bool $enabled, ?int $tenantid = null, string $settingsclass = self::SETTINGS_CLASS): void {
        if (!class_exists($settingsclass)) {
            throw new \moodle_exception('error_providerupgraderequired', self::COMPONENT);
        }
        $settingsclass::set_enabled(self::COMPONENT, $enabled, $tenantid);
    }

    /**
     * Name of the provider setting (in aiprovider_datacurso) that keeps the state of Forum AI.
     *
     * @param string $settingsclass Class that keeps the plugin state; only tests name another one.
     * @return string
     */
    public static function config_name(string $settingsclass = self::SETTINGS_CLASS): string {
        $constant = ltrim($settingsclass, '\\') . '::CONFIG_PREFIX';
        $prefix = class_exists($settingsclass) && defined($constant) ? (string) constant($constant) : 'plugin_enabled_';
        return $prefix . self::COMPONENT;
    }
}
