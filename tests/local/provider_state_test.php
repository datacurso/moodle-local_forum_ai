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
 * Tests for the Forum AI state read from the Datacurso AI provider.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group     local_forum_ai
 * @covers    \local_forum_ai\local\provider_state
 */
final class provider_state_test extends \advanced_testcase {
    /** @var string A provider class that does not exist, as on a provider older than 1.7.0. */
    private const MISSING_CLASS = '\\aiprovider_datacurso\\local\\missing_plugin_settings';

    /**
     * A provider too old to keep the plugin state leaves Forum AI disabled instead of failing.
     */
    public function test_missing_provider_class_reports_forum_ai_disabled(): void {
        $this->assertFalse(provider_state::is_enabled(settingsclass: self::MISSING_CLASS));
    }

    /**
     * A provider too old to keep the plugin state refuses the action with the upgrade message.
     */
    public function test_missing_provider_class_refuses_with_the_upgrade_message(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_providerupgraderequired', 'local_forum_ai'));

        provider_state::require_enabled(settingsclass: self::MISSING_CLASS);
    }

    /**
     * Forum AI follows the provider state when the provider keeps it.
     */
    public function test_follows_the_provider_state(): void {
        $this->require_provider();
        $this->resetAfterTest();

        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_forum_ai', true);
        $this->assertTrue(provider_state::is_enabled());
        provider_state::require_enabled();

        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_forum_ai', false);
        $this->assertFalse(provider_state::is_enabled());
        $this->expectException(\moodle_exception::class);
        provider_state::require_enabled();
    }

    /**
     * A provider too old to keep the plugin state cannot receive a state either.
     */
    public function test_missing_provider_class_is_unavailable_and_refuses_writes(): void {
        $this->assertFalse(provider_state::is_available(self::MISSING_CLASS));
        $this->assertSame('plugin_enabled_local_forum_ai', provider_state::config_name(self::MISSING_CLASS));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_providerupgraderequired', 'local_forum_ai'));
        provider_state::set_enabled(false, null, self::MISSING_CLASS);
    }

    /**
     * Moodle Workplace: the state is read and written for the tenant given, never for another tenant.
     */
    public function test_follows_the_provider_state_of_each_tenant(): void {
        $this->require_provider();
        if (!class_exists('\\tool_tenant\\tenancy')) {
            $this->markTestSkipped('Moodle Workplace (tool_tenant) is not installed.');
        }
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $tenanta = (int) $generator->create_tenant(['name' => 'Provider state A'])->id;
        $tenantb = (int) $generator->create_tenant(['name' => 'Provider state B'])->id;

        $this->assertTrue(provider_state::is_available());
        provider_state::set_enabled(false, $tenanta);

        $this->assertFalse(provider_state::is_enabled($tenanta));
        $this->assertTrue(provider_state::is_enabled($tenantb));
        provider_state::require_enabled($tenantb);

        $this->expectException(\moodle_exception::class);
        provider_state::require_enabled($tenanta);
    }

    /**
     * No plugin code other than provider_state names the provider's plugin_settings class, so
     * nothing that runs on every page (footer hook, lib.php callbacks, observers) can fail with
     * "Class not found" while the provider is older than the plugin.
     */
    public function test_only_provider_state_reads_the_provider_plugin_settings(): void {
        global $CFG;

        $root = $CFG->dirroot . '/local/forum_ai';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            $skipped = $file->getExtension() !== 'php' || str_starts_with($path, 'tests/')
                || $path === 'classes/local/provider_state.php';
            if ($skipped) {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), 'plugin_settings')) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * Skip when the provider that keeps the plugin state is not installed.
     */
    private function require_provider(): void {
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso 1.7.0 or later is not installed.');
        }
    }
}
