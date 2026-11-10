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
 * The provider stores whether the plugin is enabled, per tenant on Moodle Workplace (falling back to the
 * site default). The plugin has no switch of its own: turning Forum AI off for one tenant never affects
 * another tenant.
 *
 * @package    local_forum_ai
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forum_ai\utils
 * @covers     \local_forum_ai\observer\module
 * @covers     \local_forum_ai\task\process_single_forum_discussion
 */
final class provider_plugin_state_test extends \advanced_testcase {
    /**
     * Load the admin library the tests use.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;

        parent::setUpBeforeClass();
        require_once($CFG->libdir . '/adminlib.php');
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
     * Without tenancy: the provider state and the "Enable AI by default" switch must both be on.
     *
     * @return void
     */
    public function test_provider_and_default_ai_switch_must_both_be_on(): void {
        $this->require_provider();
        if (class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('Site level settings do not exist on Moodle Workplace; see master_switch_test.');
        }
        $this->resetAfterTest();

        plugin_settings::set_enabled('local_forum_ai', true);
        set_config('default_enabled', 0, 'local_forum_ai');
        $this->assertFalse(utils::is_ai_available());

        plugin_settings::set_enabled('local_forum_ai', false);
        set_config('default_enabled', 1, 'local_forum_ai');
        $this->assertFalse(utils::is_ai_available());

        plugin_settings::set_enabled('local_forum_ai', true);
        $this->assertTrue(utils::is_ai_available());
    }

    /**
     * Workplace: Forum AI disabled in the provider for tenant A stays enabled for tenant B, in the availability
     * checks and when a single simple discussion forum is created by a user of each tenant.
     *
     * @return void
     */
    public function test_provider_state_is_per_tenant(): void {
        global $DB;

        $this->require_provider();
        $this->require_tenancy();
        $this->resetAfterTest();

        [$tenanta, $coursea, $usera] = $this->create_tenant_with_course('Provider tenant A');
        [$tenantb, $courseb, $userb] = $this->create_tenant_with_course('Provider tenant B');

        plugin_settings::set_enabled('local_forum_ai', false, $tenanta);

        $this->assertFalse(plugin_settings::is_enabled('local_forum_ai', $tenanta));
        $this->assertTrue(plugin_settings::is_enabled('local_forum_ai', $tenantb));
        $this->assertFalse(utils::is_ai_available($tenanta, $tenanta));
        $this->assertTrue(utils::is_ai_available($tenantb, $tenantb));

        try {
            utils::require_ai_available($tenanta, $tenanta);
            $this->fail('Tenant A must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('plugindisabled', $e->errorcode);
        }
        utils::require_ai_available($tenantb, $tenantb);

        $DB->delete_records('task_adhoc', ['classname' => '\\' . task\process_single_forum_discussion::class]);
        $this->setUser($usera);
        $this->getDataGenerator()->create_module('forum', ['course' => $coursea->id, 'type' => 'single']);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(task\process_single_forum_discussion::class));
        $this->setUser($userb);
        $this->getDataGenerator()->create_module('forum', ['course' => $courseb->id, 'type' => 'single']);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(task\process_single_forum_discussion::class));
    }

    /**
     * Workplace: the provider switch follows the tenant of the user, never the tenant of the course. A user of
     * the disabled tenant A is refused in a course of the enabled tenant B, a user of B is served in a course of A.
     *
     * @return void
     */
    public function test_provider_switch_follows_the_user_tenant_not_the_course(): void {
        global $DB;

        $this->require_provider();
        $this->require_tenancy();
        $this->resetAfterTest();

        [$tenanta, $coursea, $usera] = $this->create_tenant_with_course('User tenant A');
        [$tenantb, $courseb, $userb] = $this->create_tenant_with_course('User tenant B');
        plugin_settings::set_enabled('local_forum_ai', false, $tenanta);

        // Interactive entry points: the tenant of the current user decides.
        $this->setUser($usera);
        $this->assertFalse(utils::is_ai_available($tenantb));
        try {
            utils::require_ai_available($tenantb);
            $this->fail('A user of the disabled tenant A must be refused in a course of tenant B.');
        } catch (\moodle_exception $e) {
            $this->assertSame('plugindisabled', $e->errorcode);
        }
        $this->setUser($userb);
        $this->assertTrue(utils::is_ai_available($tenanta));
        utils::require_ai_available($tenanta);

        // Observers: the tenant of the user who creates the single forum decides.
        $DB->delete_records('task_adhoc', ['classname' => '\\' . task\process_single_forum_discussion::class]);
        $this->setUser($usera);
        $this->getDataGenerator()->create_module('forum', ['course' => $courseb->id, 'type' => 'single']);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(task\process_single_forum_discussion::class));
        $this->setUser($userb);
        $this->getDataGenerator()->create_module('forum', ['course' => $coursea->id, 'type' => 'single']);
        $tasks = \core\task\manager::get_adhoc_tasks(task\process_single_forum_discussion::class);
        $this->assertCount(1, $tasks);
        $this->assertSame((int) $userb->id, (int) reset($tasks)->get_custom_data()->userid);
    }

    /**
     * Workplace: the single forum task gates on the tenant of the stored creator, not on the course nor the
     * cron user, and its retry keeps the creator.
     *
     * @return void
     */
    public function test_single_forum_task_gates_on_the_creator_tenant(): void {
        $this->require_provider();
        $this->require_tenancy();
        $this->resetAfterTest();

        [$tenanta, $coursea, $usera] = $this->create_tenant_with_course('Creator tenant A');
        [, $courseb, $userb] = $this->create_tenant_with_course('Creator tenant B');
        plugin_settings::set_enabled('local_forum_ai', false, $tenanta);
        $this->setAdminUser();

        // Creator of the disabled tenant A in a course of tenant B: skipped, no retry.
        $task = $this->make_single_forum_task_without_discussion($courseb, (int) $usera->id);
        ob_start();
        $task->execute();
        $output = ob_get_clean();
        $this->assertStringContainsString(get_string('task_skipped_plugindisabled', 'local_forum_ai'), $output);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(task\process_single_forum_discussion::class));

        // Creator of the enabled tenant B in a course of tenant A: retried for the same creator.
        $task = $this->make_single_forum_task_without_discussion($coursea, (int) $userb->id);
        ob_start();
        $task->execute();
        $output = ob_get_clean();
        $this->assertStringContainsString('Discussion not found for forum', $output);
        $tasks = \core\task\manager::get_adhoc_tasks(task\process_single_forum_discussion::class);
        $this->assertCount(1, $tasks);
        $this->assertSame((int) $userb->id, (int) reset($tasks)->get_custom_data()->userid);
    }

    /**
     * The settings page has no switch of its own: Forum AI is switched on and off from the provider.
     *
     * @return void
     */
    public function test_settings_page_has_no_forum_ai_switch(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->setAdminUser();

        $root = admin_get_root(true, true);
        $page = $root->locate('local_forum_ai_settings');
        $this->assertInstanceOf(\admin_settingpage::class, $page);

        $names = [];
        foreach ((array) $page->settings as $setting) {
            $names[] = $setting->plugin . '/' . $setting->name;
        }
        $this->assertContains('local_forum_ai/default_enabled', $names);
        $this->assertNotContains('local_forum_ai/enableforumai', $names);
    }

    /**
     * Creates a Workplace tenant with a course in its category and a member of the tenant.
     *
     * @param string $name Tenant name.
     * @return array [tenant id, course record, user record].
     */
    private function create_tenant_with_course(string $name): array {
        $category = $this->getDataGenerator()->create_category();
        $tenant = $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant([
            'name' => $name,
            'categoryid' => $category->id,
        ]);
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $user = $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_user(['tenantid' => $tenant->id]);

        return [(int) $tenant->id, $course, $user];
    }

    /**
     * Builds a single forum task, created by a user, for a forum that has no discussion yet.
     *
     * @param \stdClass $course Course of the forum.
     * @param int $creatorid User who created the forum.
     * @return task\process_single_forum_discussion
     */
    private function make_single_forum_task_without_discussion(
        \stdClass $course,
        int $creatorid
    ): task\process_single_forum_discussion {
        global $DB;

        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $DB->delete_records('task_adhoc', ['classname' => '\\' . task\process_single_forum_discussion::class]);

        $task = new task\process_single_forum_discussion();
        $task->set_custom_data([
            'forumid' => (int) $forum->id,
            'courseid' => (int) $course->id,
            'contextid' => \context_module::instance($forum->cmid)->id,
            'userid' => $creatorid,
            'retries' => 0,
        ]);
        return $task;
    }

    /**
     * Skips the current test without Moodle Workplace (tool_tenant).
     */
    private function require_tenancy(): void {
        if (!class_exists('\\tool_tenant\\tenancy')) {
            $this->markTestSkipped('Moodle Workplace (tool_tenant) is not installed.');
        }
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
