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
 * Tests for the traceability of the originating student in the response history.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

use stdClass;

global $CFG;

require_once($CFG->dirroot . '/local/forum_ai/locallib.php');
require_once(__DIR__ . '/fixtures/mock_ai_client.php');

/**
 * Tests that managing a response never erases who originated it.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\external\approve_response
 * @covers ::local_forum_ai_get_history
 * @covers \local_forum_ai\approval::create_approval_request
 * @covers \local_forum_ai\task\process_ai_post
 * @covers \local_forum_ai\task\process_ai_discussion
 */
final class history_identity_test extends \advanced_testcase {
    /**
     * Always restore the real AI client after each test.
     */
    protected function tearDown(): void {
        ai_service::set_client_for_testing(null);
        parent::tearDown();
    }

    /**
     * MDL-INT-021 (step 3) [Pendiente:fail]: after approving, the history row must
     * still identify the ORIGINATING student, not only the approving teacher.
     *
     * Asserts the CORRECT behavior. Fails on current code because
     * classes/external/approve_response.php:178 overwrites creator_userid with the
     * approving teacher, losing the student's identity (personal-data traceability
     * loss). Note: tests/privacy_provider_test.php:186 documents the privacy-side
     * workaround for this same overwrite (attribution via the parent post author);
     * this test asserts the desired fix in the history record itself.
     */
    public function test_history_identifies_originating_student_after_approval(): void {
        $setup = $this->create_setup();

        $this->setUser($setup->teacher);
        $result = external\approve_response::execute($setup->pending->approval_token, 'approve');
        $this->assertTrue($result['success']);
        $this->setAdminUser();

        $history = local_forum_ai_get_history((int) $setup->course->id);
        $this->assertArrayHasKey((int) $setup->pending->id, $history);
        $row = $history[(int) $setup->pending->id];

        $this->assertSame('approved', $row->status);
        $this->assertEquals(
            (int) $setup->student->id,
            (int) $row->creator_userid,
            'The history row must keep identifying the student who originated the response.'
        );
    }

    /**
     * MDL-INT-021 (step 3) [Pendiente:fail]: rejecting must also preserve the
     * identity of the originating student.
     *
     * Asserts the CORRECT behavior. Fails on current code because
     * classes/external/approve_response.php:184 overwrites creator_userid with the
     * rejecting teacher.
     */
    public function test_history_identifies_originating_student_after_rejection(): void {
        $setup = $this->create_setup();

        $this->setUser($setup->teacher);
        $result = external\approve_response::execute($setup->pending->approval_token, 'reject');
        $this->assertTrue($result['success']);
        $this->setAdminUser();

        $history = local_forum_ai_get_history((int) $setup->course->id);
        $this->assertArrayHasKey((int) $setup->pending->id, $history);
        $row = $history[(int) $setup->pending->id];

        $this->assertSame('rejected', $row->status);
        $this->assertEquals(
            (int) $setup->student->id,
            (int) $row->creator_userid,
            'The history row must keep identifying the student who originated the response.'
        );
    }

    /**
     * MDL-INT-021 (step 3, teacher side — green): the acting teacher is recorded:
     * the published post is attributed to the approver and the management
     * timestamps are stored.
     */
    public function test_acting_teacher_is_recorded_on_approval(): void {
        global $DB;

        $setup = $this->create_setup();

        $this->setUser($setup->teacher);
        $result = external\approve_response::execute($setup->pending->approval_token, 'approve');
        $this->assertTrue($result['success']);

        $row = $DB->get_record('local_forum_ai_pending', ['id' => $setup->pending->id], '*', MUST_EXIST);

        // The published forum post is attributed to the approving teacher.
        $post = $DB->get_record('forum_posts', ['id' => $row->postid], '*', MUST_EXIST);
        $this->assertEquals((int) $setup->teacher->id, (int) $post->userid);

        // The acting teacher is recorded in its own column.
        $this->assertEquals((int) $setup->teacher->id, (int) $row->action_userid);

        // The management is timestamped.
        $this->assertSame('approved', $row->status);
        $this->assertNotEmpty($row->approved_at);
        $this->assertNotEmpty($row->timemodified);
    }

    /**
     * MDL-INT-021 (step 3, automatic mode): an automatically published reply keeps
     * the student who wrote the post as creator and records the grader as the
     * user who managed it.
     */
    public function test_auto_mode_keeps_student_as_creator_and_grader_as_manager(): void {
        global $DB;

        $setup = $this->create_auto_setup(['require_approval' => 0]);
        $this->set_forum_config($setup, ['graderid' => $setup->teacher->id]);
        $post = $this->create_student_reply($setup);

        ai_service::set_client_for_testing(new mock_ai_client(['reply' => 'Automatic AI answer']));
        $task = new task\process_ai_post();
        $task->set_custom_data((object) ['postid' => $post->id, 'cmid' => $setup->cm->id]);
        $this->run_task($task);

        $row = $DB->get_record('local_forum_ai_pending', ['forumid' => $setup->forum->id], '*', MUST_EXIST);
        $this->assertSame('approved', $row->status);
        $this->assertNotEmpty($row->postid);
        $this->assertEquals((int) $setup->student->id, (int) $row->creator_userid);
        $this->assertEquals((int) $setup->teacher->id, (int) $row->action_userid);
        $this->assertNotEmpty($row->approved_at);

        $history = local_forum_ai_get_history((int) $setup->course->id);
        $this->assertArrayHasKey((int) $row->id, $history);
        $this->assertEquals((int) $setup->student->id, (int) $history[$row->id]->creator_userid);
        $this->assertEquals((int) $setup->teacher->id, (int) $history[$row->id]->action_userid);
    }

    /**
     * MDL-INT-021 (step 3, automatic mode, topic reply): the reply to the opening
     * post keeps the discussion author as creator and the grader as manager.
     */
    public function test_auto_mode_topic_reply_keeps_author_as_creator(): void {
        global $DB;

        $setup = $this->create_auto_setup(['require_approval' => 0, 'enablediainitconversation' => 1]);
        $this->set_forum_config($setup, ['graderid' => $setup->teacher->id]);

        ai_service::set_client_for_testing(new mock_ai_client(['reply' => 'Welcome to the discussion']));
        $task = new task\process_ai_discussion();
        $task->set_custom_data((object) ['discussionid' => $setup->discussion->id, 'cmid' => $setup->cm->id]);
        $this->run_task($task);

        $row = $DB->get_record('local_forum_ai_pending', ['forumid' => $setup->forum->id], '*', MUST_EXIST);
        $this->assertSame('approved', $row->status);
        $this->assertEquals((int) $setup->discussion->firstpost, (int) $row->parentpostid);
        $this->assertEquals((int) $setup->student->id, (int) $row->creator_userid);
        $this->assertEquals((int) $setup->teacher->id, (int) $row->action_userid);
        $this->assertNotEmpty($row->approved_at);
    }

    /**
     * MDL-INT-021 (step 3, approval mode): the queued row identifies the student
     * and has no manager until a teacher approves it.
     */
    public function test_approval_mode_leaves_action_user_empty_until_managed(): void {
        global $DB;

        $setup = $this->create_auto_setup(['require_approval' => 1]);
        $post = $this->create_student_reply($setup);

        ai_service::set_client_for_testing(new mock_ai_client(['reply' => 'Pending AI answer']));
        $sink = $this->redirectMessages();
        $task = new task\process_ai_post();
        $task->set_custom_data((object) ['postid' => $post->id, 'cmid' => $setup->cm->id]);
        $this->run_task($task);
        $sink->close();

        $row = $DB->get_record('local_forum_ai_pending', ['forumid' => $setup->forum->id], '*', MUST_EXIST);
        $this->assertSame('pending', $row->status);
        $this->assertEquals((int) $setup->student->id, (int) $row->creator_userid);
        $this->assertNull($row->action_userid);
        $this->assertNull($row->approved_at);

        $this->setUser($setup->teacher);
        $result = external\approve_response::execute($row->approval_token, 'approve');
        $this->assertTrue($result['success']);

        $row = $DB->get_record('local_forum_ai_pending', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertEquals((int) $setup->student->id, (int) $row->creator_userid);
        $this->assertEquals((int) $setup->teacher->id, (int) $row->action_userid);
    }

    /**
     * Creates a course, users, forum and discussion with a plugin config row.
     *
     * @param array $configoverrides Plugin config fields to set.
     * @return stdClass Setup holder (course, student, teacher, forum, cm, discussion).
     */
    private function create_auto_setup(array $configoverrides): stdClass {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = new stdClass();
        $setup->course = $this->getDataGenerator()->create_course();
        $setup->student = $this->getDataGenerator()->create_and_enrol($setup->course, 'student');
        $setup->teacher = $this->getDataGenerator()->create_and_enrol($setup->course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $setup->course->id]);
        $setup->forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);
        $setup->cm = get_coursemodule_from_instance('forum', $setup->forum->id, $setup->course->id, false, MUST_EXIST);

        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $setup->course->id,
            'forum' => $setup->forum->id,
            'userid' => $setup->student->id,
        ]);
        $setup->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $this->set_forum_config($setup, $configoverrides);

        return $setup;
    }

    /**
     * Creates or updates the plugin config row of the setup forum.
     *
     * @param stdClass $setup Setup holder.
     * @param array $overrides Config fields to set.
     */
    private function set_forum_config(stdClass $setup, array $overrides): void {
        global $DB;

        $config = $DB->get_record('local_forum_ai_config', ['forumid' => $setup->forum->id]) ?: new stdClass();
        $config->forumid = $setup->forum->id;
        $config->enabled = 1;
        $config->require_approval = $config->require_approval ?? 1;
        $config->usedelay = 0;
        $config->allowedroles = (string) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $config->reply_message = 'Test prompt';
        $config->timemodified = time();
        foreach ($overrides as $field => $value) {
            $config->{$field} = $value;
        }

        if (empty($config->id)) {
            $config->timecreated = time();
            $DB->insert_record('local_forum_ai_config', $config);
        } else {
            $DB->update_record('local_forum_ai_config', $config);
        }
    }

    /**
     * Creates a student reply to the opening post of the setup discussion.
     *
     * @param stdClass $setup Setup holder.
     * @return stdClass The new post record.
     */
    private function create_student_reply(stdClass $setup): stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_post([
            'discussion' => $setup->discussion->id,
            'parent' => $setup->discussion->firstpost,
            'userid' => $setup->student->id,
            'message' => 'Please review my answer',
        ]);
    }

    /**
     * Executes a task discarding its mtrace output.
     *
     * @param \core\task\task_base $task Task instance.
     */
    private function run_task(\core\task\task_base $task): void {
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Creates a course, student, teacher, forum, discussion and a pending row.
     *
     * @return stdClass Setup holder (course, student, teacher, forum, discussion, pending).
     */
    private function create_setup(): stdClass {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = new stdClass();
        $setup->course = $this->getDataGenerator()->create_course();
        $setup->student = $this->getDataGenerator()->create_and_enrol($setup->course, 'student');
        $setup->teacher = $this->getDataGenerator()->create_and_enrol($setup->course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $setup->course->id]);
        $setup->forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $setup->course->id,
            'forum' => $setup->forum->id,
            'userid' => $setup->student->id,
        ]);
        $setup->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $pending = (object) [
            'discussionid' => $setup->discussion->id,
            'forumid' => $setup->forum->id,
            'parentpostid' => $setup->discussion->firstpost,
            'creator_userid' => $setup->student->id,
            'subject' => 'Re: ' . $setup->discussion->name,
            'message' => '<p>AI reply awaiting review</p>',
            'status' => 'pending',
            'approval_token' => md5(uniqid('history_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);
        $setup->pending = $pending;

        return $setup;
    }
}
