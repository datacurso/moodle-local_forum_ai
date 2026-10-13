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
 * Tests that the master switches (site or tenant) only gate their own tenant.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

use local_forum_ai\local\tenant_config;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_ai_client.php');

/**
 * Unit tests for the tenant aware availability checks, observers, tasks, queue and forum disabling.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\utils
 * @covers \local_forum_ai\observer\post
 * @covers \local_forum_ai\task\process_ai_post
 * @covers \local_forum_ai\task\process_ai_discussion
 * @covers \local_forum_ai\task\process_ai_queue
 */
final class master_switch_test extends \advanced_testcase {
    /**
     * Always restore the real AI client after each test.
     */
    protected function tearDown(): void {
        ai_service::set_client_for_testing(null);
        parent::tearDown();
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
     * Skips the test on Moodle Workplace, where a site level switch does not exist (every tenant has its own).
     */
    private function require_no_tenancy(): void {
        if (class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('There is no site level switch on Moodle Workplace.');
        }
    }

    /**
     * Creates a course (inside a new tenant when requested) with a forum, an enabled AI config and a discussion.
     *
     * @param bool $intenant Whether the course belongs to a new tenant.
     * @return stdClass Holder with course, student, teacher, cm, forum, discussion and tenantid.
     */
    private function create_environment(bool $intenant): stdClass {
        global $DB;

        $env = new stdClass();
        $env->tenantid = 0;
        $options = [];
        if ($intenant) {
            $category = $this->getDataGenerator()->create_category();
            $tenant = $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant([
                'name' => 'Switch tenant ' . random_string(5),
                'categoryid' => $category->id,
            ]);
            $env->tenantid = (int) $tenant->id;
            $options['category'] = $category->id;
        }
        $env->course = $this->getDataGenerator()->create_course($options);
        $env->student = $this->getDataGenerator()->create_and_enrol($env->course, 'student');
        $env->teacher = $this->getDataGenerator()->create_and_enrol($env->course, 'editingteacher');
        $module = $this->getDataGenerator()->create_module('forum', ['course' => $env->course->id]);
        $env->cm = get_coursemodule_from_instance('forum', $module->id, $env->course->id, false, MUST_EXIST);
        $env->forum = $DB->get_record('forum', ['id' => $module->id], '*', MUST_EXIST);
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $env->course->id,
            'forum' => $env->forum->id,
            'userid' => $env->student->id,
        ]);
        $env->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);
        $DB->insert_record('local_forum_ai_config', (object) [
            'forumid' => $env->forum->id,
            'enabled' => 1,
            'require_approval' => 1,
            'allowedroles' => (string) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST),
            'reply_message' => 'Test prompt',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return $env;
    }

    /**
     * Creates a reply of the student in the discussion of an environment.
     *
     * @param stdClass $env Environment.
     * @return stdClass Post record.
     */
    private function create_reply(stdClass $env): stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_post([
            'discussion' => $env->discussion->id,
            'parent' => $env->discussion->firstpost,
            'userid' => $env->student->id,
            'message' => 'Student reply',
        ]);
    }

    /**
     * Turns a master switch off for a tenant (or for the site when there is no tenant).
     *
     * @param int $tenantid Tenant id, 0 for the site.
     * @param string $setting Setting name (enableforumai or default_enabled).
     */
    private function switch_off(int $tenantid, string $setting): void {
        if ($tenantid) {
            tenant_config::set($setting, $tenantid, 0);
        } else {
            set_config($setting, 0, 'local_forum_ai');
        }
    }

    /**
     * Combinations of tenancy and master switch.
     *
     * @return array
     */
    public static function switch_provider(): array {
        return [
            'site, feature switch' => [false, 'enableforumai'],
            'site, default ai switch' => [false, 'default_enabled'],
            'tenant, feature switch' => [true, 'enableforumai'],
            'tenant, default ai switch' => [true, 'default_enabled'],
        ];
    }

    /**
     * Applies the tenancy requirement of a data provider case.
     *
     * @param bool $intenant Whether the case needs Workplace.
     */
    private function require_case(bool $intenant): void {
        if ($intenant) {
            $this->require_tenancy();
        } else {
            $this->require_no_tenancy();
        }
    }

    /**
     * The availability helpers follow the switches of the tenant.
     *
     * @dataProvider switch_provider
     * @param bool $intenant Whether the course belongs to a tenant.
     * @param string $setting Switch that is turned off.
     */
    public function test_availability_follows_the_switches(bool $intenant, string $setting): void {
        $this->resetAfterTest();
        $this->require_case($intenant);
        $env = $this->create_environment($intenant);

        $this->assertTrue(utils::is_ai_available($env->tenantid));

        $this->switch_off($env->tenantid, $setting);

        $this->assertFalse(utils::is_ai_available($env->tenantid));
        $this->expectException(\moodle_exception::class);
        utils::require_ai_available($env->tenantid);
    }

    /**
     * Turning the switches off for one tenant leaves another tenant and the site values untouched.
     */
    public function test_switch_of_one_tenant_does_not_affect_another(): void {
        $this->resetAfterTest();
        $this->require_tenancy();
        $envb = $this->create_environment(true);
        $enva = $this->create_environment(true);
        $siteconfig = get_config('local_forum_ai');

        tenant_config::set('enableforumai', $enva->tenantid, 0);
        tenant_config::set('default_enabled', $enva->tenantid, 0);

        $this->assertFalse(utils::is_ai_available($enva->tenantid));
        $this->assertTrue(utils::is_ai_available($envb->tenantid));
        $this->assertTrue(utils::is_feature_enabled($envb->tenantid));
        $this->assertTrue(utils::is_global_ai_enabled($envb->tenantid));
        $this->assertEquals($siteconfig, get_config('local_forum_ai'));
    }

    /**
     * Site switches never turn a tenant off under Workplace.
     */
    public function test_site_switches_do_not_affect_a_tenant(): void {
        $this->resetAfterTest();
        $this->require_tenancy();
        $env = $this->create_environment(true);

        set_config('enableforumai', 0, 'local_forum_ai');
        set_config('default_enabled', 0, 'local_forum_ai');

        $this->assertTrue(utils::is_ai_available($env->tenantid));
        $this->assertTrue(utils::is_ai_available(0));
    }

    /**
     * Without tenancy the switches are the site ones, as before.
     */
    public function test_site_switches_gate_the_site_without_tenancy(): void {
        $this->resetAfterTest();
        $this->require_no_tenancy();

        $this->assertTrue(utils::is_ai_available());

        set_config('default_enabled', 0, 'local_forum_ai');
        $this->assertFalse(utils::is_ai_available());
        $this->assertFalse(utils::is_ai_available(0));
        $this->assertTrue(utils::is_feature_enabled());
    }

    /**
     * The tenant of a forum comes from its course and falls back to the tenant of the user.
     */
    public function test_get_tenant_id_resolves_course_then_user(): void {
        $this->resetAfterTest();
        $this->require_tenancy();
        $env = $this->create_environment(true);
        $shared = $this->getDataGenerator()->create_course();
        $tenantuser = $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_user([
            'tenantid' => $env->tenantid,
        ]);

        $this->assertSame($env->tenantid, utils::get_tenant_id((int) $env->course->id));
        $this->assertSame(0, utils::get_tenant_id((int) $shared->id));
        $this->assertSame($env->tenantid, utils::get_tenant_id((int) $shared->id, (int) $tenantuser->id));
    }

    /**
     * Defaults of the helper getters come from the tenant, not from the site.
     */
    public function test_default_getters_use_the_tenant_values(): void {
        $this->resetAfterTest();
        set_config('default_delayminutes', 5, 'local_forum_ai');
        set_config('default_question_turns', 3, 'local_forum_ai');
        set_config('default_replyinlocked', 1, 'local_forum_ai');
        tenant_config::set('default_delayminutes', 41, 20);
        tenant_config::set('default_question_turns', 41, 2);
        tenant_config::set('default_replyinlocked', 41, 1);

        $this->assertSame(20, utils::get_default_delay_minutes(41));
        $this->assertSame(2, utils::get_default_question_turns(41));
        $this->assertTrue(utils::get_default_reply_in_locked(41));
        $this->assertSame(60, utils::get_default_delay_minutes(42));
        $this->assertSame(1, utils::get_default_question_turns(42));
        $this->assertFalse(utils::get_default_reply_in_locked(42));
        $this->assertSame(2, utils::get_effective_question_turns(null, 41));
        $this->assertTrue(utils::get_effective_reply_in_locked(null, 41));
    }

    /**
     * Without tenancy the getters keep reading the site configuration.
     */
    public function test_default_getters_use_the_site_without_tenancy(): void {
        $this->resetAfterTest();
        $this->require_no_tenancy();
        set_config('default_delayminutes', 5, 'local_forum_ai');
        set_config('default_question_turns', 3, 'local_forum_ai');
        set_config('default_replyinlocked', 1, 'local_forum_ai');

        $this->assertSame(5, utils::get_default_delay_minutes());
        $this->assertSame(3, utils::get_default_question_turns());
        $this->assertTrue(utils::get_default_reply_in_locked());
    }

    /**
     * Disabling the forums of a tenant leaves the forums of the other tenants and of the site as they are.
     */
    public function test_disable_all_forums_ai_only_affects_the_tenant(): void {
        global $DB;

        $this->resetAfterTest();
        $this->require_tenancy();
        $enva = $this->create_environment(true);
        $envb = $this->create_environment(true);
        $site = $this->create_environment(false);

        utils::disable_all_forums_ai($enva->tenantid);

        $this->assertEquals(0, $DB->get_field('local_forum_ai_config', 'enabled', ['forumid' => $enva->forum->id]));
        $this->assertEquals(1, $DB->get_field('local_forum_ai_config', 'enabled', ['forumid' => $envb->forum->id]));
        $this->assertEquals(1, $DB->get_field('local_forum_ai_config', 'enabled', ['forumid' => $site->forum->id]));
    }

    /**
     * Without a tenant every forum is disabled, as on a plain LMS.
     */
    public function test_disable_all_forums_ai_without_tenant_disables_every_forum(): void {
        global $DB;

        $this->resetAfterTest();
        $first = $this->create_environment(false);
        $second = $this->create_environment(false);

        utils::disable_all_forums_ai();

        $this->assertEquals(0, $DB->get_field('local_forum_ai_config', 'enabled', ['forumid' => $first->forum->id]));
        $this->assertEquals(0, $DB->get_field('local_forum_ai_config', 'enabled', ['forumid' => $second->forum->id]));

        $DB->set_field('local_forum_ai_config', 'enabled', 1);
        utils::disable_all_forums_ai(0);
        $this->assertSame(0, $DB->count_records('local_forum_ai_config', ['enabled' => 1]));
    }

    /**
     * A reply in a tenant with the AI disabled queues nothing, while another tenant keeps queueing.
     */
    public function test_post_observer_only_skips_the_disabled_tenant(): void {
        global $DB;

        $this->resetAfterTest();
        $this->require_tenancy();
        $enva = $this->create_environment(true);
        $envb = $this->create_environment(true);
        tenant_config::set('default_enabled', $enva->tenantid, 0);

        foreach ([$enva, $envb] as $env) {
            $post = $this->create_reply($env);
            observer\post::post_created(\mod_forum\event\post_created::create([
                'context' => \context_module::instance($env->cm->id),
                'objectid' => $post->id,
                'other' => [
                    'discussionid' => $env->discussion->id,
                    'forumid' => $env->forum->id,
                    'forumtype' => $env->forum->type,
                ],
            ]));
        }

        $tasks = $DB->get_records('task_adhoc', ['component' => 'local_forum_ai']);
        $this->assertCount(1, $tasks);
        $this->assertSame('\local_forum_ai\task\process_ai_post', reset($tasks)->classname);
    }

    /**
     * Runs the ad hoc task of a post and returns the AI requests it sent.
     *
     * @param stdClass $env Environment.
     * @param stdClass $post Post to process.
     * @param mock_ai_client $mock Injected AI client.
     * @return string Captured output.
     */
    private function run_post_task(stdClass $env, stdClass $post, mock_ai_client $mock): string {
        ai_service::set_client_for_testing($mock);
        $task = new task\process_ai_post();
        $task->set_custom_data((object) ['postid' => $post->id, 'cmid' => $env->cm->id]);
        $messagesink = $this->redirectMessages();
        ob_start();
        try {
            $task->execute();
        } finally {
            $output = ob_get_clean();
            $messagesink->close();
        }

        return (string) $output;
    }

    /**
     * The ad hoc task of a disabled tenant does not call the AI, the one of an enabled tenant does.
     */
    public function test_post_task_only_skips_the_disabled_tenant(): void {
        global $DB;

        $this->resetAfterTest();
        $this->require_tenancy();
        $enva = $this->create_environment(true);
        $envb = $this->create_environment(true);
        tenant_config::set('enableforumai', $enva->tenantid, 0);
        $posta = $this->create_reply($enva);
        $postb = $this->create_reply($envb);

        $mocka = new mock_ai_client(['reply' => 'Answer']);
        $this->run_post_task($enva, $posta, $mocka);
        $mockb = new mock_ai_client(['reply' => 'Answer']);
        $this->run_post_task($envb, $postb, $mockb);

        $this->assertCount(0, $mocka->requests);
        $this->assertCount(1, $mockb->requests);
        $this->assertSame(0, $DB->count_records('local_forum_ai_pending', ['forumid' => $enva->forum->id]));
        $this->assertSame(1, $DB->count_records('local_forum_ai_pending', ['forumid' => $envb->forum->id]));
    }

    /**
     * A tenant default (enabled in the tenant) applies to a forum without its own configuration row.
     */
    public function test_task_uses_the_tenant_default_when_the_forum_has_no_row(): void {
        global $DB;

        $this->resetAfterTest();
        $this->require_tenancy();
        $env = $this->create_environment(true);
        $DB->delete_records('local_forum_ai_config', ['forumid' => $env->forum->id]);
        $post = $this->create_reply($env);

        $mock = new mock_ai_client(['reply' => 'Answer']);
        $this->run_post_task($env, $post, $mock);
        // Without a row the student archetype applies and the default enables the AI.
        $this->assertCount(1, $mock->requests);
        $this->assertSame(get_string('default_reply_message', 'local_forum_ai'), $mock->requests[0]['body']['prompt']);

        tenant_config::set('default_reply_message', $env->tenantid, 'Tenant message');
        $mock = new mock_ai_client(['reply' => 'Answer']);
        $this->run_post_task($env, $post, $mock);
        $this->assertSame('Tenant message', $mock->requests[0]['body']['prompt']);
    }

    /**
     * Inserts a delayed queue row for a post.
     *
     * @param stdClass $post Post.
     * @param stdClass $env Environment.
     * @return int Queue row id.
     */
    private function create_queue_row(stdClass $post, stdClass $env): int {
        global $DB;

        return (int) $DB->insert_record('local_forum_ai_queue', (object) [
            'type' => 'post',
            'payload' => json_encode((object) ['postid' => $post->id, 'cmid' => $env->cm->id]),
            'timecreated' => time(),
            'timetoprocess' => time() - 100,
            'processed' => 0,
        ]);
    }

    /**
     * The queue drops the items of a disabled tenant and still dispatches the items of the other tenants.
     */
    public function test_queue_only_drops_the_items_of_the_disabled_tenant(): void {
        global $DB;

        $this->resetAfterTest();
        $this->require_tenancy();
        $enva = $this->create_environment(true);
        $envb = $this->create_environment(true);
        tenant_config::set('default_enabled', $enva->tenantid, 0);
        $rowa = $this->create_queue_row($this->create_reply($enva), $enva);
        $rowb = $this->create_queue_row($this->create_reply($envb), $envb);

        ob_start();
        (new task\process_ai_queue())->execute();
        ob_end_clean();

        $this->assertFalse($DB->record_exists('local_forum_ai_queue', ['id' => $rowa]));
        $this->assertFalse($DB->record_exists('local_forum_ai_queue', ['id' => $rowb]));
        $this->assertSame(1, $DB->count_records('task_adhoc', ['component' => 'local_forum_ai']));
    }

    /**
     * Without tenancy a disabled site leaves the queue untouched, as before.
     */
    public function test_queue_is_untouched_when_the_site_is_disabled_without_tenancy(): void {
        global $DB;

        $this->resetAfterTest();
        $this->require_no_tenancy();
        $env = $this->create_environment(false);
        $row = $this->create_queue_row($this->create_reply($env), $env);
        set_config('default_enabled', 0, 'local_forum_ai');

        ob_start();
        (new task\process_ai_queue())->execute();
        ob_end_clean();

        $this->assertTrue($DB->record_exists('local_forum_ai_queue', ['id' => $row]));
        $this->assertSame(0, $DB->count_records('task_adhoc', ['component' => 'local_forum_ai']));
    }

    /**
     * The AI client refuses to send data for a tenant with the AI disabled and keeps serving the other tenants.
     */
    public function test_ai_service_is_refused_only_for_the_disabled_tenant(): void {
        $this->resetAfterTest();
        $this->require_tenancy();
        $enva = $this->create_environment(true);
        $envb = $this->create_environment(true);
        tenant_config::set('default_enabled', $enva->tenantid, 0);
        $mock = new mock_ai_client(['reply' => 'Answer']);
        ai_service::set_client_for_testing($mock);

        try {
            ai_service::call_ai_service(['prompt' => 'x'], $enva->tenantid);
            $this->fail('The disabled tenant must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_aidisabled', $e->errorcode);
        }
        $this->assertCount(0, $mock->requests);

        $this->assertSame('Answer', ai_service::call_ai_service(['prompt' => 'x'], $envb->tenantid)['reply']);
        $this->assertCount(1, $mock->requests);
    }

    /**
     * The delay of a tenant keeps the minimum of one minute.
     */
    public function test_tenant_delay_keeps_the_minimum(): void {
        $this->resetAfterTest();

        tenant_config::set('default_delayminutes', 77, 0);

        $this->assertSame(1, utils::get_default_delay_minutes(77));
    }
}
