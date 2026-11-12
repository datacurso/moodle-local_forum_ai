<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_forum_ai;

use aiprovider_datacurso\local\plugin_settings;

/**
 * Tests that the state of Forum AI is kept by the Datacurso AI provider.
 *
 * The plugin no longer has its own "Enable Forum AI" setting: the provider stores whether the plugin
 * is enabled, and the former setting is removed on upgrade.
 *
 * @package    local_forum_ai
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forum_ai\utils
 */
final class provider_plugin_state_test extends \advanced_testcase {
    /**
     * Load the admin and upgrade libraries the tests use.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;

        parent::setUpBeforeClass();
        require_once($CFG->libdir . '/adminlib.php');
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/forum_ai/db/upgrade.php');
    }

    /**
     * S6: the feature follows the state saved in the provider.
     *
     * @return void
     */
    public function test_feature_follows_the_provider_state(): void {
        $this->require_provider();

        $this->resetAfterTest();
        set_config('default_enabled', 1, 'local_forum_ai');

        unset_config(plugin_settings::CONFIG_PREFIX . 'local_forum_ai', 'aiprovider_datacurso');
        $this->assertTrue(utils::is_ai_available(), 'Without a saved state the plugin is enabled.');

        plugin_settings::set_enabled('local_forum_ai', false);
        $this->assertFalse(utils::is_ai_available());

        plugin_settings::set_enabled('local_forum_ai', true);
        $this->assertTrue(utils::is_ai_available());
    }

    /**
     * S6: the former setting of the plugin is no longer read.
     *
     * @return void
     */
    public function test_former_setting_is_ignored(): void {
        $this->require_provider();

        $this->resetAfterTest();
        set_config('default_enabled', 1, 'local_forum_ai');

        plugin_settings::set_enabled('local_forum_ai', true);
        set_config('enableforumai', 0, 'local_forum_ai');
        $this->assertTrue(utils::is_ai_available());

        plugin_settings::set_enabled('local_forum_ai', false);
        set_config('enableforumai', 1, 'local_forum_ai');
        $this->assertFalse(utils::is_ai_available());
    }

    /**
     * S7: the settings page no longer defines nor reseeds the former setting.
     *
     * @return void
     */
    public function test_settings_page_no_longer_defines_the_setting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        unset_config('enableforumai', 'local_forum_ai');

        $root = admin_get_root(true, true);
        $page = $root->locate('local_forum_ai_settings');
        $this->assertInstanceOf(\admin_settingpage::class, $page);

        $names = [];
        foreach ((array) $page->settings as $setting) {
            $names[] = $setting->plugin . '/' . $setting->name;
        }
        $this->assertContains('local_forum_ai/default_enabled', $names);
        $this->assertNotContains('local_forum_ai/enableforumai', $names);
        $this->assertFalse(get_config('local_forum_ai', 'enableforumai'), 'The settings page must not reseed it.');
    }

    /**
     * S7: the upgrade removes the former setting.
     *
     * @return void
     */
    public function test_upgrade_removes_the_former_setting(): void {
        $this->resetAfterTest();

        set_config('enableforumai', '1', 'local_forum_ai');
        set_config('version', 2026110500, 'local_forum_ai');

        xmldb_local_forum_ai_upgrade(2026110500);

        $this->assertFalse(get_config('local_forum_ai', 'enableforumai'));
    }

    /**
     * Skips the current test when the Datacurso AI provider is not installed.
     *
     * The CI pipeline does not install declared dependencies; without the Datacurso AI provider
     * the forum creation callbacks and AI entry points reached by these tests cannot run.
     */
    private function require_provider(): void {
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
    }
}
