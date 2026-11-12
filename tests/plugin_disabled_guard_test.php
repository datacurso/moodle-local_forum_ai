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

defined('MOODLE_INTERNAL') || die();

use aiprovider_datacurso\local\plugin_settings;
use core_external\external_api;
use local_forum_ai\external\approve_response;
use local_forum_ai\external\get_details;
use local_forum_ai\external\get_discussion_data;
use local_forum_ai\external\update_response;
use local_forum_ai\task\process_single_forum_discussion;
use moodle_exception;

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests that Forum AI stops its pages, web services, observers and tasks while it is disabled
 * in the Datacurso AI provider.
 *
 * @package    local_forum_ai
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forum_ai\external\get_details
 * @covers     \local_forum_ai\external\approve_response
 * @covers     \local_forum_ai\external\update_response
 * @covers     \local_forum_ai\external\get_discussion_data
 * @covers     \local_forum_ai\observer\module
 * @covers     \local_forum_ai\task\process_single_forum_discussion
 */
final class plugin_disabled_guard_test extends \externallib_advanced_testcase {
    /**
     * Web services that act on a pending AI response, with the arguments they need.
     *
     * @return array
     */
    public static function web_service_provider(): array {
        return [
            'get_details' => [get_details::class, []],
            'approve_response' => [approve_response::class, ['reject']],
            'update_response' => [update_response::class, ['<p>Edited.</p>']],
            'get_discussion_data' => [get_discussion_data::class, []],
        ];
    }

    /**
     * A disabled plugin refuses the web service with the provider error and changes nothing.
     *
     * @dataProvider web_service_provider
     * @param string $classname External function class.
     * @param array $args Arguments after the token.
     * @return void
     */
    public function test_web_service_refused_when_disabled(string $classname, array $args): void {
        global $DB;

        $this->require_provider();

        $this->resetAfterTest();
        [$pending, $teacher] = $this->create_pending_response();
        $this->setUser($teacher);

        plugin_settings::set_enabled('local_forum_ai', false);

        try {
            $classname::execute($pending->approval_token, ...$args);
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('plugindisabled', $e->errorcode);
            $this->assertSame('aiprovider_datacurso', $e->module);
        }

        $stored = $DB->get_record('local_forum_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertSame('pending', $stored->status);
        $this->assertSame($pending->message, $stored->message);
    }

    /**
     * An enabled plugin keeps serving the web service.
     *
     * @dataProvider web_service_provider
     * @param string $classname External function class.
     * @param array $args Arguments after the token.
     * @return void
     */
    public function test_web_service_works_when_enabled(string $classname, array $args): void {
        $this->require_provider();

        $this->resetAfterTest();
        [$pending, $teacher] = $this->create_pending_response();
        $this->setUser($teacher);

        plugin_settings::set_enabled('local_forum_ai', true);

        $result = $classname::execute($pending->approval_token, ...$args);
        // Pre-existing behaviour: the detail services build author names from partial user records
        // and raise a missing-name-fields notice; it is pinned in token_services_permissions_test.
        $this->resetDebugging();
        $result = external_api::clean_returnvalue($classname::execute_returns(), $result);
        $this->assertNotEmpty($result);
    }

    /**
     * A disabled plugin does not queue the single forum task when a single forum is created.
     *
     * @return void
     */
    public function test_single_forum_creation_queues_nothing_when_disabled(): void {
        $this->require_provider();

        $this->resetAfterTest();
        plugin_settings::set_enabled('local_forum_ai', false);

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'type' => 'single']);

        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(process_single_forum_discussion::class));
    }

    /**
     * An enabled plugin queues the single forum task when a single forum is created.
     *
     * @return void
     */
    public function test_single_forum_creation_queues_task_when_enabled(): void {
        $this->require_provider();

        $this->resetAfterTest();
        plugin_settings::set_enabled('local_forum_ai', true);

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'type' => 'single']);

        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(process_single_forum_discussion::class));
    }

    /**
     * A disabled plugin makes the single forum task skip before any retry is queued.
     *
     * @return void
     */
    public function test_single_forum_task_skips_without_requeue_when_disabled(): void {
        $this->require_provider();

        $this->resetAfterTest();
        plugin_settings::set_enabled('local_forum_ai', false);

        $task = $this->make_single_forum_task_without_discussion();

        $this->expectOutputString(get_string('task_skipped_plugindisabled', 'local_forum_ai') . "\n");
        $task->execute();

        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(process_single_forum_discussion::class));
    }

    /**
     * An enabled plugin keeps the retry logic of the single forum task.
     *
     * @return void
     */
    public function test_single_forum_task_requeues_when_enabled(): void {
        $this->require_provider();

        $this->resetAfterTest();
        plugin_settings::set_enabled('local_forum_ai', true);

        $task = $this->make_single_forum_task_without_discussion();

        $this->expectOutputRegex('/Discussion not found for forum/');
        $task->execute();

        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(process_single_forum_discussion::class));
    }

    /**
     * Pages reached by URL call the provider guard for the tenant of the current user right after require_login,
     * before any capability check, token lookup or output (the tenant of the course never decides).
     *
     * @dataProvider page_provider
     * @param string $page Page file name.
     * @param string[] $allowed Calls allowed between require_login and the guard.
     * @return void
     */
    public function test_page_requires_enabled_after_login(string $page, array $allowed): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/local/forum_ai/' . $page);
        // Through provider_state, so a provider older than the plugin state refuses the page instead of failing.
        $guard = "\\local_forum_ai\\local\\provider_state::require_enabled();";

        $loginpos = strrpos($source, 'require_login(');
        $guardpos = strpos($source, $guard);
        $this->assertNotFalse($guardpos, "{$page} must call the provider guard for the tenant of the current user.");
        $this->assertGreaterThan($loginpos, $guardpos, "{$page} must call the guard after require_login.");

        // No capability check, token lookup or page output may happen before the guard.
        $between = substr($source, $loginpos, $guardpos - $loginpos);
        foreach (array_diff(['require_capability(', 'approval::', '$PAGE->', '$OUTPUT->'], $allowed) as $call) {
            $this->assertStringNotContainsString($call, $between, "{$page} must call the guard before {$call}");
        }
    }

    /**
     * Pages of the plugin reached by URL, with the calls they may make before the guard.
     *
     * @return array
     */
    public static function page_provider(): array {
        return [
            'pending.php' => ['pending.php', []],
            'history.php' => ['history.php', []],
            'review.php' => ['review.php', []],
        ];
    }

    /**
     * Builds a single forum task for a forum that has no discussion yet.
     *
     * @return process_single_forum_discussion
     */
    private function make_single_forum_task_without_discussion(): process_single_forum_discussion {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $DB->delete_records('task_adhoc', ['classname' => '\\' . process_single_forum_discussion::class]);

        $task = new process_single_forum_discussion();
        $task->set_custom_data([
            'forumid' => (int) $forum->id,
            'courseid' => (int) $course->id,
            'contextid' => \context_module::instance($forum->cmid)->id,
            'retries' => 0,
        ]);
        return $task;
    }

    /**
     * Creates a discussion with a pending AI response and a teacher who can manage it.
     *
     * @return array [$pending, $teacher].
     */
    private function create_pending_response(): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
        ]);
        $pending = $this->getDataGenerator()->get_plugin_generator('local_forum_ai')->create_pending_response([
            'discussionid' => $discussion->id,
        ]);

        return [$pending, $teacher];
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
