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
 * Tests for the tenant licence failure handling and the tenant aware AI client.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_ai_client.php');

/**
 * Unit tests for a missing or invalid AI licence.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\ai_service
 * @covers \local_forum_ai\approval::record_failed_attempt
 * @covers \local_forum_ai\task\process_ai_post
 * @covers \local_forum_ai\task\process_ai_discussion
 */
final class licence_failure_test extends \advanced_testcase {
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
     * Skips the test when Moodle Workplace tenancy is installed.
     */
    private function require_no_tenancy(): void {
        if (class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('Only meaningful on a site without Workplace.');
        }
    }

    /**
     * Creates a course with a forum with AI enabled, a student, a teacher approver and a discussion.
     *
     * @return stdClass Holder with course, student, teacher, cm, forum, discussion.
     */
    private function create_environment(): stdClass {
        global $DB;

        $env = new stdClass();
        $options = [];
        if (class_exists('\tool_tenant\tenancy')) {
            $category = $this->getDataGenerator()->create_category();
            $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant([
                'name' => 'Licence tenant ' . random_string(5),
                'categoryid' => $category->id,
            ]);
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
            'enablediainitconversation' => 1,
            'allowedroles' => (string) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST),
            'reply_message' => 'Test prompt',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return $env;
    }

    /**
     * Creates a student reply in the discussion of the environment.
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
     * Provider exceptions that mean the licence is not usable.
     *
     * @return array
     */
    public static function licence_error_provider(): array {
        return [
            'missing or invalid key' => ['invalidlicensekey'],
            'license not allowed' => ['license_not_allowed'],
        ];
    }

    /**
     * Licence errors of the provider are recognised on Workplace.
     *
     * @dataProvider licence_error_provider
     * @param string $code Provider error code.
     */
    public function test_licence_errors_are_recognised(string $code): void {
        $this->require_tenancy();

        $this->assertTrue(ai_service::is_licence_failure(new \moodle_exception($code, 'aiprovider_datacurso')));
    }

    /**
     * Other errors are not treated as licence failures.
     */
    public function test_other_errors_are_not_licence_failures(): void {
        $this->assertFalse(ai_service::is_licence_failure(new \moodle_exception('httperror', 'aiprovider_datacurso', '', 500)));
        $this->assertFalse(ai_service::is_licence_failure(new \moodle_exception('invalidlicensekey', 'error')));
        $this->assertFalse(ai_service::is_licence_failure(new \coding_exception('x')));
    }

    /**
     * A plain LMS keeps the behaviour it always had: licence errors are not recorded.
     */
    public function test_licence_errors_are_not_recorded_without_tenancy(): void {
        $this->require_no_tenancy();

        $this->assertFalse(ai_service::is_licence_failure(new \moodle_exception('invalidlicensekey', 'aiprovider_datacurso')));
    }

    /**
     * The provider client is built for the resolved tenant so cron and ad hoc tasks use its licence.
     */
    public function test_client_arguments_carry_the_resolved_tenant(): void {
        $this->assertSame([null, 42], ai_service::get_provider_client_arguments(42));
    }

    /**
     * Without a resolved tenant the provider keeps its default behaviour.
     */
    public function test_client_arguments_without_tenant_keep_provider_default(): void {
        $this->assertSame([], ai_service::get_provider_client_arguments(0));
    }

    /**
     * A failed attempt is stored once, with a fixed text, and notifies the approvers of the forum.
     */
    public function test_record_failed_attempt_stores_row_and_notifies_approvers(): void {
        global $DB;

        $this->resetAfterTest();
        $env = $this->create_environment();
        $post = $this->create_reply($env);
        $sink = $this->redirectMessages();

        $rowid = approval::record_failed_attempt($env->discussion, $env->forum, (int) $post->id, (int) $env->student->id);
        $again = approval::record_failed_attempt($env->discussion, $env->forum, (int) $post->id, (int) $env->student->id);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertGreaterThan(0, $rowid);
        $this->assertSame(0, $again);
        $row = $DB->get_record('local_forum_ai_pending', ['id' => $rowid], '*', MUST_EXIST);
        $this->assertSame('failed', $row->status);
        $this->assertSame((int) $post->id, (int) $row->parentpostid);
        $this->assertSame(get_string('failure_licence_record', 'local_forum_ai'), $row->message);

        $recipients = array_map(static fn($message) => (int) $message->useridto, $messages);
        $this->assertContains((int) $env->teacher->id, $recipients);
        $this->assertNotContains((int) $env->student->id, $recipients);
        foreach ($messages as $message) {
            $this->assertSame('ai_failure', $message->eventtype);
            $this->assertStringNotContainsString('shop.datacurso.com', $message->fullmessage);
        }
    }

    /**
     * A failed row is listed in the history and never in the pending list nor counted as an AI turn.
     */
    public function test_failed_row_goes_to_history_only(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $env = $this->create_environment();
        $post = $this->create_reply($env);
        $sink = $this->redirectMessages();
        approval::record_failed_attempt($env->discussion, $env->forum, (int) $post->id, (int) $env->student->id);
        $sink->close();
        require_once(__DIR__ . '/../locallib.php');

        $history = local_forum_ai_get_history((int) $env->course->id, (int) $env->forum->id);
        $this->assertCount(1, $history);
        $this->assertSame('failed', reset($history)->status);
        $this->assertSame(0, $DB->count_records('local_forum_ai_pending', ['status' => 'pending']));
        $this->assertSame(
            0,
            utils::count_prior_ai_turns_in_thread((int) $env->discussion->id, (int) $post->id)
        );
    }

    /**
     * Runs a task capturing its output.
     *
     * @param \core\task\task_base $task Task.
     * @return string Captured output.
     */
    private function run_task(\core\task\task_base $task): string {
        ob_start();
        try {
            $task->execute();
        } finally {
            $output = ob_get_clean();
        }

        return (string) $output;
    }

    /**
     * A reply that cannot be answered because of the licence is recorded and the approvers are told.
     */
    public function test_post_task_records_the_failure_without_retrying(): void {
        global $DB;

        $this->resetAfterTest();
        $this->require_tenancy();
        $env = $this->create_environment();
        $post = $this->create_reply($env);
        $mock = new mock_ai_client();
        $mock->fail_with(new \moodle_exception('invalidlicensekey', 'aiprovider_datacurso'));
        ai_service::set_client_for_testing($mock);
        $sink = $this->redirectMessages();

        $task = new task\process_ai_post();
        $task->set_custom_data((object) ['postid' => $post->id, 'cmid' => $env->cm->id]);
        $output = $this->run_task($task);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertStringContainsString('no usable AI licence', $output);
        $this->assertSame(1, $DB->count_records('local_forum_ai_pending', ['forumid' => $env->forum->id, 'status' => 'failed']));
        $this->assertNotEmpty($messages);
    }

    /**
     * The same applies to the reply to the first post of a discussion.
     */
    public function test_discussion_task_records_the_failure(): void {
        global $DB;

        $this->resetAfterTest();
        $this->require_tenancy();
        $env = $this->create_environment();
        $mock = new mock_ai_client();
        $mock->fail_with(new \moodle_exception('license_not_allowed', 'aiprovider_datacurso'));
        ai_service::set_client_for_testing($mock);
        $sink = $this->redirectMessages();

        $task = new task\process_ai_discussion();
        $task->set_custom_data((object) ['discussionid' => $env->discussion->id, 'cmid' => $env->cm->id]);
        $this->run_task($task);
        $sink->close();

        $row = $DB->get_record('local_forum_ai_pending', ['forumid' => $env->forum->id], '*', MUST_EXIST);
        $this->assertSame('failed', $row->status);
        $this->assertSame((int) $env->discussion->firstpost, (int) $row->parentpostid);
    }

    /**
     * Other failures keep their behaviour: the task fails and nothing is recorded.
     */
    public function test_other_failures_are_still_thrown(): void {
        global $DB;

        $this->resetAfterTest();
        $this->require_tenancy();
        $env = $this->create_environment();
        $post = $this->create_reply($env);
        $mock = new mock_ai_client();
        $mock->fail_with(new \moodle_exception('httperror', 'aiprovider_datacurso', '', 500));
        ai_service::set_client_for_testing($mock);

        $task = new task\process_ai_post();
        $task->set_custom_data((object) ['postid' => $post->id, 'cmid' => $env->cm->id]);
        try {
            $this->run_task($task);
            $this->fail('The failure must be rethrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('httperror', $e->errorcode);
        }

        $this->assertDebuggingCalled();
        $this->assertSame(0, $DB->count_records('local_forum_ai_pending', ['status' => 'failed']));
    }

    /**
     * Without Workplace a licence error still fails the task as before.
     */
    public function test_licence_error_is_thrown_without_tenancy(): void {
        global $DB;

        $this->resetAfterTest();
        $this->require_no_tenancy();
        $env = $this->create_environment();
        $post = $this->create_reply($env);
        $mock = new mock_ai_client();
        $mock->fail_with(new \moodle_exception('invalidlicensekey', 'aiprovider_datacurso'));
        ai_service::set_client_for_testing($mock);

        $task = new task\process_ai_post();
        $task->set_custom_data((object) ['postid' => $post->id, 'cmid' => $env->cm->id]);
        try {
            $this->run_task($task);
            $this->fail('The failure must be rethrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidlicensekey', $e->errorcode);
        }

        $this->assertDebuggingCalled();
        $this->assertSame(0, $DB->count_records('local_forum_ai_pending', ['status' => 'failed']));
    }
}
