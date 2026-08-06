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

/**
 * Tests for the allowed-roles trigger filter of local_forum_ai.
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
 * Allowed-roles interpretation and its effect on the AI trigger pipeline.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\role_checker
 * @covers \local_forum_ai\task\process_ai_post
 */
final class role_trigger_test extends \advanced_testcase {
    /**
     * Always restore the real AI client after each test.
     */
    protected function tearDown(): void {
        ai_service::set_client_for_testing(null);
        parent::tearDown();
    }

    /**
     * MDL-UNIT-009: an empty or unset role list means every role triggers the
     * AI, including teachers.
     */
    public function test_empty_role_list_allows_every_user(): void {
        $this->resetAfterTest();

        $fixture = $this->create_fixture();

        $this->assertTrue(role_checker::user_has_allowed_role(
            (int) $fixture->forum->id,
            (int) $fixture->student->id,
            ''
        ));
        $this->assertTrue(role_checker::user_has_allowed_role(
            (int) $fixture->forum->id,
            (int) $fixture->teacher->id,
            ''
        ));
        $this->assertTrue(role_checker::user_has_allowed_role(
            (int) $fixture->forum->id,
            (int) $fixture->teacher->id,
            []
        ));
    }

    /**
     * MDL-UNIT-009: with a configured list only the included roles are allowed.
     */
    public function test_configured_role_list_restricts_to_included_roles(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $teacherroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);

        $onlystudents = (string) $studentroleid;
        $this->assertTrue(role_checker::user_has_allowed_role(
            (int) $fixture->forum->id,
            (int) $fixture->student->id,
            $onlystudents
        ));
        $this->assertFalse(role_checker::user_has_allowed_role(
            (int) $fixture->forum->id,
            (int) $fixture->teacher->id,
            $onlystudents
        ));

        $both = $studentroleid . ',' . $teacherroleid;
        $this->assertTrue(role_checker::user_has_allowed_role(
            (int) $fixture->forum->id,
            (int) $fixture->teacher->id,
            $both
        ));
    }

    /**
     * MDL-UNIT-009: malformed stored values neither error out nor allow roles
     * that are not part of the list.
     */
    public function test_malformed_role_values_do_not_error_nor_allow(): void {
        $this->resetAfterTest();

        $fixture = $this->create_fixture();

        // Garbage CSV: non-numeric tokens, blanks, zero and unknown role ids.
        $malformed = 'abc, ,0,999999,;drop';
        $this->assertFalse(role_checker::user_has_allowed_role(
            (int) $fixture->forum->id,
            (int) $fixture->student->id,
            $malformed
        ));
        $this->assertFalse(role_checker::user_has_allowed_role(
            (int) $fixture->forum->id,
            (int) $fixture->teacher->id,
            $malformed
        ));

        // Malformed array values behave the same way.
        $this->assertFalse(role_checker::user_has_allowed_role(
            (int) $fixture->forum->id,
            (int) $fixture->student->id,
            ['abc', null, 999999]
        ));
    }

    /**
     * MDL-UNIT-009 / MDL-INT-006: the allowed role is recognized regardless of
     * the context where it was assigned (module, course, category, system).
     */
    public function test_role_recognized_in_any_assignment_context(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $allowed = (string) $studentroleid;

        $coursecontext = \context_course::instance($fixture->course->id);
        $modulecontext = \context_module::instance($fixture->cm->id);
        $categorycontext = $coursecontext->get_parent_context();
        $systemcontext = \context_system::instance();

        // Course context: the enrolled student already holds the role there.
        $this->assertTrue(role_checker::user_has_allowed_role(
            (int) $fixture->forum->id,
            (int) $fixture->student->id,
            $allowed
        ));

        // Module context.
        $moduleuser = $this->getDataGenerator()->create_user();
        role_assign($studentroleid, $moduleuser->id, $modulecontext->id);
        $this->assertTrue(role_checker::user_has_allowed_role(
            (int) $fixture->forum->id,
            (int) $moduleuser->id,
            $allowed
        ));

        // Category context.
        $categoryuser = $this->getDataGenerator()->create_user();
        role_assign($studentroleid, $categoryuser->id, $categorycontext->id);
        $this->assertTrue(role_checker::user_has_allowed_role(
            (int) $fixture->forum->id,
            (int) $categoryuser->id,
            $allowed
        ));

        // System context.
        $systemuser = $this->getDataGenerator()->create_user();
        role_assign($studentroleid, $systemuser->id, $systemcontext->id);
        $this->assertTrue(role_checker::user_has_allowed_role(
            (int) $fixture->forum->id,
            (int) $systemuser->id,
            $allowed
        ));

        // A user with no assignment anywhere remains excluded.
        $unassigned = $this->getDataGenerator()->create_user();
        $this->assertFalse(role_checker::user_has_allowed_role(
            (int) $fixture->forum->id,
            (int) $unassigned->id,
            $allowed
        ));
    }

    /**
     * MDL-INT-006: with only "Student" allowed, a teacher post never generates
     * an AI response nor calls the AI service.
     */
    public function test_teacher_post_does_not_generate_reply_when_only_students_allowed(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->set_forum_config((int) $fixture->forum->id, ['allowedroles' => (string) $studentroleid]);

        $post = $this->create_reply($fixture, (int) $fixture->teacher->id, 'Teacher reply');

        $mock = $this->inject_mock();
        $output = $this->run_post_task((int) $post->id, (int) $fixture->cm->id);

        $this->assertStringContainsString('no allowed role', $output);
        $this->assertCount(0, $mock->requests);
        $this->assertSame(0, $DB->count_records('local_forum_ai_pending'));
    }

    /**
     * MDL-INT-006: a post authored by the configured grader never triggers the
     * AI, even when the role filter would allow it.
     */
    public function test_grader_own_post_never_triggers(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $this->set_forum_config((int) $fixture->forum->id, [
            'allowedroles' => '',
            'graderid' => $fixture->teacher->id,
        ]);

        $post = $this->create_reply($fixture, (int) $fixture->teacher->id, 'Grader reply');

        $mock = $this->inject_mock();
        $output = $this->run_post_task((int) $post->id, (int) $fixture->cm->id);

        $this->assertStringContainsString('authored by the AI grader user', $output);
        $this->assertCount(0, $mock->requests);
        $this->assertSame(0, $DB->count_records('local_forum_ai_pending'));
    }

    /**
     * MDL-INT-006: with an empty role list any user triggers the AI response.
     */
    public function test_empty_role_list_triggers_for_any_user(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $this->set_forum_config((int) $fixture->forum->id, ['allowedroles' => '']);

        $post = $this->create_reply($fixture, (int) $fixture->teacher->id, 'Unrestricted teacher reply');

        $mock = $this->inject_mock(['reply' => 'AI reply for anyone']);
        $messagesink = $this->redirectMessages();
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);
        $messagesink->close();

        $this->assertCount(1, $mock->requests);
        $this->assertSame(1, $DB->count_records('local_forum_ai_pending', ['forumid' => $fixture->forum->id]));
    }

    /**
     * Creates course, users, forum, discussion and an enabled config row.
     *
     * @return stdClass Fixture holder (course, student, teacher, forum, cm, discussion).
     */
    private function create_fixture(): stdClass {
        global $DB;

        $fixture = new stdClass();
        $fixture->course = $this->getDataGenerator()->create_course();
        $fixture->student = $this->getDataGenerator()->create_and_enrol($fixture->course, 'student');
        $fixture->teacher = $this->getDataGenerator()->create_and_enrol($fixture->course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $fixture->course->id]);
        $fixture->cm = get_coursemodule_from_instance('forum', $forummodule->id, $fixture->course->id, false, MUST_EXIST);
        $fixture->forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $fixture->course->id,
            'forum' => $fixture->forum->id,
            'userid' => $fixture->student->id,
        ]);
        $fixture->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $this->set_forum_config((int) $fixture->forum->id);

        return $fixture;
    }

    /**
     * Creates or updates the plugin config row for a forum.
     *
     * @param int $forumid Forum ID.
     * @param array $overrides Extra config fields to set.
     */
    private function set_forum_config(int $forumid, array $overrides = []): void {
        global $DB;

        $configrow = $DB->get_record('local_forum_ai_config', ['forumid' => $forumid]) ?: new stdClass();
        $configrow->forumid = $forumid;
        $configrow->enabled = 1;
        $configrow->require_approval = 1;
        $configrow->allowedroles = '';
        $configrow->reply_message = 'Test prompt';
        $configrow->timemodified = time();
        foreach ($overrides as $field => $value) {
            $configrow->{$field} = $value;
        }

        if (empty($configrow->id)) {
            $configrow->timecreated = time();
            $DB->insert_record('local_forum_ai_config', $configrow);
        } else {
            $DB->update_record('local_forum_ai_config', $configrow);
        }
    }

    /**
     * Creates a reply post in the fixture discussion.
     *
     * @param stdClass $fixture Fixture holder.
     * @param int $userid Post author.
     * @param string $message Post message.
     * @return stdClass The new post record.
     */
    private function create_reply(stdClass $fixture, int $userid, string $message): stdClass {
        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');

        return $forumgenerator->create_post([
            'discussion' => $fixture->discussion->id,
            'parent' => $fixture->discussion->firstpost,
            'userid' => $userid,
            'message' => $message,
        ]);
    }

    /**
     * Runs the process_ai_post adhoc task for a post, capturing mtrace output.
     *
     * @param int $postid Post id.
     * @param int $cmid Course module id.
     * @return string Captured output.
     */
    private function run_post_task(int $postid, int $cmid): string {
        $task = new task\process_ai_post();
        $task->set_custom_data((object) ['postid' => $postid, 'cmid' => $cmid]);

        ob_start();
        try {
            $task->execute();
        } finally {
            $output = ob_get_clean();
        }

        return (string) $output;
    }

    /**
     * Injects a fresh mock AI client through the test seam.
     *
     * @param array|null $defaultresponse Default canned response.
     * @return mock_ai_client The injected mock.
     */
    private function inject_mock(?array $defaultresponse = ['reply' => 'Mock AI reply']): mock_ai_client {
        $mock = new mock_ai_client($defaultresponse);
        ai_service::set_client_for_testing($mock);

        return $mock;
    }
}
