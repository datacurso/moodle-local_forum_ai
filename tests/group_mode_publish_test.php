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
 * Tests for group mode handling in the automatic publication path.
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
 * MDL-INT-016: automatic publication must respect separate-groups membership.
 *
 * When the configured grader cannot reply in the discussion group, the
 * automatic flow degrades to manual approval (pending row + notification),
 * the same as the "no grader configured" path.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\utils::can_user_reply_in_discussion_group
 * @covers \local_forum_ai\task\process_ai_post
 * @covers \local_forum_ai\task\process_ai_discussion
 * @covers \local_forum_ai\approval::publish_ai_post
 */
final class group_mode_publish_test extends \advanced_testcase {
    /**
     * Always restore the real AI client after each test.
     */
    protected function tearDown(): void {
        ai_service::set_client_for_testing(null);
        parent::tearDown();
    }

    /**
     * A grader outside the separate group (no accessallgroups) must not publish:
     * the response stays pending and teachers are notified.
     */
    public function test_auto_mode_grader_outside_group_degrades_to_pending(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture(SEPARATEGROUPS);
        $this->prohibit_accessallgroups($fixture);
        $post = $this->create_reply($fixture);

        $this->inject_mock(['reply' => 'Group AI answer']);
        $messagesink = $this->redirectMessages();
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);
        $messages = $messagesink->get_messages();
        $messagesink->close();

        $this->assert_degraded_to_pending($fixture, $messages);
    }

    /**
     * A grader who is a member of the discussion group publishes as before.
     */
    public function test_auto_mode_grader_member_of_group_publishes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture(SEPARATEGROUPS);
        $this->prohibit_accessallgroups($fixture);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $fixture->groupb->id,
            'userid' => $fixture->grader->id,
        ]);
        $post = $this->create_reply($fixture);

        $this->inject_mock(['reply' => 'Group AI answer']);
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);

        $this->assert_published_by_grader($fixture);
    }

    /**
     * A grader with moodle/site:accessallgroups publishes in any group.
     */
    public function test_auto_mode_grader_with_accessallgroups_publishes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Editing teachers have accessallgroups by default.
        $fixture = $this->create_fixture(SEPARATEGROUPS);
        $post = $this->create_reply($fixture);

        $this->inject_mock(['reply' => 'Group AI answer']);
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);

        $this->assert_published_by_grader($fixture);
    }

    /**
     * Forums without group mode keep publishing automatically.
     */
    public function test_auto_mode_without_group_mode_is_unchanged(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture(NOGROUPS);
        $this->prohibit_accessallgroups($fixture);
        $post = $this->create_reply($fixture);

        $this->inject_mock(['reply' => 'Group AI answer']);
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);

        $this->assert_published_by_grader($fixture);
    }

    /**
     * The initial-topic reply task degrades to pending in the same situation.
     */
    public function test_topic_reply_outside_group_degrades_to_pending(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture(SEPARATEGROUPS, ['enablediainitconversation' => 1]);
        $this->prohibit_accessallgroups($fixture);

        $this->inject_mock(['reply' => 'Group AI answer']);
        $messagesink = $this->redirectMessages();
        $task = new task\process_ai_discussion();
        $task->set_custom_data((object) [
            'discussionid' => $fixture->discussion->id,
            'cmid' => $fixture->cm->id,
        ]);
        $this->run_task_capturing_output($task);
        $messages = $messagesink->get_messages();
        $messagesink->close();

        $this->assert_degraded_to_pending($fixture, $messages);
    }

    /**
     * Defense in depth: publish_ai_post() refuses to publish on behalf of an
     * author who cannot reply in the discussion group, before creating anything.
     */
    public function test_publish_ai_post_refuses_author_outside_group(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture(SEPARATEGROUPS);
        $this->prohibit_accessallgroups($fixture);
        $pendingid = approval::create_approval_request(
            $fixture->discussion,
            $fixture->forum,
            'Direct publish',
            'approved',
            (int) $fixture->discussion->firstpost,
            null,
            (int) $fixture->grader->id
        );
        $pending = $DB->get_record('local_forum_ai_pending', ['id' => $pendingid], '*', MUST_EXIST);
        $postcount = $DB->count_records('forum_posts');

        $result = approval::publish_ai_post(
            $fixture->discussion,
            $fixture->forum,
            $fixture->cm,
            $fixture->course,
            $pending,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->grader->id
        );
        $this->resetDebugging();

        $this->assertFalse($result);
        $this->assertSame($postcount, $DB->count_records('forum_posts'));
    }

    /**
     * Data provider for {@see test_can_user_reply_in_discussion_group()}.
     *
     * @return array
     */
    public static function can_user_reply_in_discussion_group_provider(): array {
        return [
            'No group mode' => [NOGROUPS, false, false, false, true],
            'Visible groups, all participants' => [VISIBLEGROUPS, false, false, false, true],
            'Visible groups, other group' => [VISIBLEGROUPS, true, false, false, false],
            'Separate groups, all participants' => [SEPARATEGROUPS, false, false, false, false],
            'Separate groups, member' => [SEPARATEGROUPS, true, true, false, true],
            'Separate groups, non member' => [SEPARATEGROUPS, true, false, false, false],
            'Separate groups, accessallgroups' => [SEPARATEGROUPS, true, false, true, true],
        ];
    }

    /**
     * Group reply matrix for an arbitrary user (not $USER).
     *
     * @dataProvider can_user_reply_in_discussion_group_provider
     * @param int $groupmode Activity group mode.
     * @param bool $ingroup Whether the discussion belongs to group B (else all participants).
     * @param bool $member Whether the user is a member of group B.
     * @param bool $accessallgroups Whether the user has moodle/site:accessallgroups.
     * @param bool $expected Expected result.
     */
    public function test_can_user_reply_in_discussion_group(
        int $groupmode,
        bool $ingroup,
        bool $member,
        bool $accessallgroups,
        bool $expected
    ): void {
        global $DB;

        $this->resetAfterTest();
        // The current user can access all groups: the helper must not use $USER.
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_and_enrol($course, 'student');
        $group = $generator->create_group(['courseid' => $course->id]);
        if ($member) {
            $generator->create_group_member(['groupid' => $group->id, 'userid' => $user->id]);
        }
        if ($accessallgroups) {
            $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
            assign_capability(
                'moodle/site:accessallgroups',
                CAP_ALLOW,
                $roleid,
                \context_course::instance($course->id)->id,
                true
            );
        }
        $forum = $generator->create_module('forum', ['course' => $course->id, 'groupmode' => $groupmode]);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);
        $discussion = (object) ['groupid' => $ingroup ? (int) $group->id : -1];

        $this->assertSame(
            $expected,
            utils::can_user_reply_in_discussion_group($cm, $course, $discussion, (int) $user->id)
        );
    }

    /**
     * Builds a course with groups A and B and a forum in automatic mode whose
     * discussion (started by a group B student) belongs to group B.
     *
     * @param int $groupmode Activity group mode.
     * @param array $configoverrides Extra plugin config fields.
     * @return stdClass Fixture holder.
     */
    private function create_fixture(int $groupmode, array $configoverrides = []): stdClass {
        global $DB;

        $generator = $this->getDataGenerator();
        $fixture = new stdClass();
        $fixture->course = $generator->create_course();
        $fixture->student = $generator->create_and_enrol($fixture->course, 'student');
        $fixture->grader = $generator->create_and_enrol($fixture->course, 'editingteacher');
        $generator->create_group(['courseid' => $fixture->course->id, 'name' => 'Group A']);
        $fixture->groupb = $generator->create_group(['courseid' => $fixture->course->id, 'name' => 'Group B']);
        $generator->create_group_member(['groupid' => $fixture->groupb->id, 'userid' => $fixture->student->id]);

        $forummodule = $generator->create_module('forum', [
            'course' => $fixture->course->id,
            'groupmode' => $groupmode,
        ]);
        $fixture->cm = get_coursemodule_from_instance('forum', $forummodule->id, $fixture->course->id, false, MUST_EXIST);
        $fixture->forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $discussion = $generator->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $fixture->course->id,
            'forum' => $fixture->forum->id,
            'userid' => $fixture->student->id,
            'groupid' => $groupmode == NOGROUPS ? -1 : $fixture->groupb->id,
        ]);
        $fixture->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $config = (object) array_merge([
            'forumid' => $fixture->forum->id,
            'enabled' => 1,
            'require_approval' => 0,
            'usedelay' => 0,
            'graderid' => $fixture->grader->id,
            'allowedroles' => (string) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST),
            'reply_message' => 'Test prompt',
            'timecreated' => time(),
            'timemodified' => time(),
        ], $configoverrides);
        $DB->insert_record('local_forum_ai_config', $config);

        return $fixture;
    }

    /**
     * Prohibits moodle/site:accessallgroups for editing teachers in the fixture course.
     *
     * @param stdClass $fixture Fixture holder.
     */
    private function prohibit_accessallgroups(stdClass $fixture): void {
        global $DB;

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability(
            'moodle/site:accessallgroups',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($fixture->course->id)->id,
            true
        );
    }

    /**
     * Creates a student reply to the first post of the fixture discussion.
     *
     * @param stdClass $fixture Fixture holder.
     * @return stdClass The new post record.
     */
    private function create_reply(stdClass $fixture): stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_post([
            'discussion' => $fixture->discussion->id,
            'parent' => $fixture->discussion->firstpost,
            'userid' => $fixture->student->id,
            'message' => 'Student reply in group B',
        ]);
    }

    /**
     * Asserts the response stayed pending, nothing was published by the grader
     * and an approval notification was sent.
     *
     * @param stdClass $fixture Fixture holder.
     * @param array $messages Messages captured by the sink.
     */
    private function assert_degraded_to_pending(stdClass $fixture, array $messages): void {
        global $DB;

        $this->assertSame(0, $DB->count_records('forum_posts', ['userid' => $fixture->grader->id]));
        $pending = $DB->get_record('local_forum_ai_pending', ['forumid' => $fixture->forum->id], '*', MUST_EXIST);
        $this->assertSame('pending', $pending->status);
        $this->assertEmpty($pending->postid);

        $approvals = array_filter($messages, static function ($message): bool {
            return $message->component === 'local_forum_ai' && $message->eventtype === 'ai_approval_request';
        });
        $this->assertNotEmpty($approvals, 'The degraded response must notify the approvers.');
    }

    /**
     * Asserts the AI response was published in the discussion by the grader.
     *
     * @param stdClass $fixture Fixture holder.
     */
    private function assert_published_by_grader(stdClass $fixture): void {
        global $DB;

        $pending = $DB->get_record('local_forum_ai_pending', ['forumid' => $fixture->forum->id], '*', MUST_EXIST);
        $this->assertSame('approved', $pending->status);
        $this->assertNotEmpty($pending->postid);
        $published = $DB->get_record('forum_posts', ['id' => $pending->postid], '*', MUST_EXIST);
        $this->assertSame((int) $fixture->grader->id, (int) $published->userid);
        $this->assertSame((int) $fixture->discussion->id, (int) $published->discussion);
    }

    /**
     * Runs the process_ai_post adhoc task for a post.
     *
     * @param int $postid Post id.
     * @param int $cmid Course module id.
     */
    private function run_post_task(int $postid, int $cmid): void {
        $task = new task\process_ai_post();
        $task->set_custom_data((object) ['postid' => $postid, 'cmid' => $cmid]);
        $this->run_task_capturing_output($task);
    }

    /**
     * Executes a task while capturing its mtrace output.
     *
     * @param \core\task\task_base $task Task instance.
     * @return string Captured output.
     */
    private function run_task_capturing_output(\core\task\task_base $task): string {
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
     * @param array $defaultresponse Default canned response.
     * @return mock_ai_client The injected mock.
     */
    private function inject_mock(array $defaultresponse): mock_ai_client {
        $mock = new mock_ai_client($defaultresponse);
        ai_service::set_client_for_testing($mock);

        return $mock;
    }
}
