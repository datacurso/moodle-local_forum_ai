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
 * Tests for separate-groups isolation of every Forum AI entry point.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

use externallib_advanced_testcase;
use local_forum_ai\external\approve_response;
use local_forum_ai\external\get_details;
use local_forum_ai\external\get_discussion_data;
use local_forum_ai\external\process_review;
use local_forum_ai\external\update_response;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once(__DIR__ . '/../locallib.php');
require_once(__DIR__ . '/fixtures/mock_ai_client.php');

/**
 * FAI-SEC-001-R1: a teacher without moodle/site:accessallgroups in a
 * separate-groups forum must only reach the AI responses of the discussions
 * of their own groups (plus discussions for all participants) through
 * listings, history, detail, actions, notifications and the AI payload.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\local\group_access
 * @covers \local_forum_ai\approval
 * @covers \local_forum_ai\utils::build_forum_ai_payload
 * @covers \local_forum_ai\external\approve_response
 * @covers \local_forum_ai\external\get_details
 * @covers \local_forum_ai\external\get_discussion_data
 * @covers \local_forum_ai\external\update_response
 * @covers \local_forum_ai\external\process_review
 * @covers ::local_forum_ai_get_pending
 * @covers ::local_forum_ai_get_history
 */
final class group_isolation_test extends externallib_advanced_testcase {
    /**
     * Always restore the real AI client after each test.
     */
    protected function tearDown(): void {
        ai_service::set_client_for_testing(null);
        parent::tearDown();
    }

    /**
     * The pending list of a group teacher excludes the rows of other groups.
     */
    public function test_get_pending_excludes_other_group_rows(): void {
        $this->resetAfterTest();
        $fixture = $this->create_fixture();

        $this->setUser($fixture->teachera);

        foreach ([0, (int) $fixture->forum->id] as $forumid) {
            $rows = local_forum_ai_get_pending((int) $fixture->course->id, $forumid);
            $this->assertArrayHasKey((int) $fixture->pendinga->id, $rows);
            $this->assertArrayNotHasKey((int) $fixture->pendingb->id, $rows);
        }

        // The explicit user parameter evaluates the same rules for another user.
        $this->setAdminUser();
        $rows = local_forum_ai_get_pending((int) $fixture->course->id, 0, (int) $fixture->teachera->id);
        $this->assertArrayHasKey((int) $fixture->pendinga->id, $rows);
        $this->assertArrayNotHasKey((int) $fixture->pendingb->id, $rows);
    }

    /**
     * The history of a group teacher excludes the rows of other groups.
     */
    public function test_get_history_excludes_other_group_rows(): void {
        $this->resetAfterTest();
        $fixture = $this->create_fixture();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_forum_ai');
        $historya = $generator->create_pending_response([
            'discussionid' => $fixture->discussiona->id,
            'status' => 'approved',
        ]);
        $historyb = $generator->create_pending_response([
            'discussionid' => $fixture->discussionb->id,
            'status' => 'rejected',
        ]);

        $this->setUser($fixture->teachera);

        foreach ([0, (int) $fixture->forum->id] as $forumid) {
            $rows = local_forum_ai_get_history((int) $fixture->course->id, $forumid);
            $this->assertArrayHasKey((int) $historya->id, $rows);
            $this->assertArrayNotHasKey((int) $historyb->id, $rows);
        }
    }

    /**
     * Discussions for all participants (groupid -1) stay visible to a group teacher.
     */
    public function test_all_participants_discussion_visible_to_group_teacher(): void {
        $this->resetAfterTest();
        $fixture = $this->create_fixture();

        $this->setUser($fixture->teachera);

        $rows = local_forum_ai_get_pending((int) $fixture->course->id);
        $this->assertArrayHasKey((int) $fixture->pendingall->id, $rows);

        $details = get_details::execute($fixture->pendingall->approval_token);
        $this->assertSame('pending', $details['status']);
        // Pre-existing fullname() debugging of the post authors, unrelated to groups.
        $this->resetDebugging();
    }

    /**
     * A teacher holding moodle/site:accessallgroups sees the rows of every group.
     */
    public function test_accessallgroups_sees_all_groups(): void {
        $this->resetAfterTest();
        $fixture = $this->create_fixture();

        // Editing teachers keep accessallgroups and belong to no group.
        $this->setUser($fixture->editingteacher);

        $rows = local_forum_ai_get_pending((int) $fixture->course->id);
        $this->assertArrayHasKey((int) $fixture->pendinga->id, $rows);
        $this->assertArrayHasKey((int) $fixture->pendingb->id, $rows);
        $this->assertArrayHasKey((int) $fixture->pendingall->id, $rows);

        $details = get_details::execute($fixture->pendingb->approval_token);
        $this->assertSame('pending', $details['status']);
        // Pre-existing fullname() debugging of the post authors, unrelated to groups.
        $this->resetDebugging();
    }

    /**
     * Course-level listings honour per-forum capability overrides and visibility.
     */
    public function test_course_level_pending_respects_module_capability_override(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->create_fixture();

        // A second forum, without group mode, where approval is prohibited for teachers.
        $otherforum = $this->getDataGenerator()->create_module('forum', ['course' => $fixture->course->id]);
        $otherdiscussion = $this->create_discussion($fixture, $otherforum, $fixture->studenta, -1);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_forum_ai');
        $otherpending = $generator->create_pending_response(['discussionid' => $otherdiscussion->id]);
        $otherhistory = $generator->create_pending_response([
            'discussionid' => $otherdiscussion->id,
            'status' => 'approved',
        ]);
        $teacherroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability(
            'local/forum_ai:approveresponses',
            CAP_PROHIBIT,
            $teacherroleid,
            \context_module::instance($otherforum->cmid)->id,
            true
        );

        $this->setUser($fixture->teachera);

        $rows = local_forum_ai_get_pending((int) $fixture->course->id);
        $this->assertArrayHasKey((int) $fixture->pendinga->id, $rows);
        $this->assertArrayNotHasKey((int) $otherpending->id, $rows);

        $history = local_forum_ai_get_history((int) $fixture->course->id);
        $this->assertArrayNotHasKey((int) $otherhistory->id, $history);
    }

    /**
     * get_details refuses a token of another group's discussion.
     */
    public function test_get_details_rejects_other_group_token(): void {
        $this->resetAfterTest();
        $fixture = $this->create_fixture();

        $this->setUser($fixture->teachera);

        $this->assert_not_in_group(fn() => get_details::execute($fixture->pendingb->approval_token));

        // The own group's token keeps working.
        $details = get_details::execute($fixture->pendinga->approval_token);
        $this->assertSame('pending', $details['status']);
        // Pre-existing fullname() debugging of the post authors, unrelated to groups.
        $this->resetDebugging();
    }

    /**
     * get_discussion_data refuses a token of another group's discussion.
     */
    public function test_get_discussion_data_rejects_other_group_token(): void {
        $this->resetAfterTest();
        $fixture = $this->create_fixture();

        $this->setUser($fixture->teachera);

        $this->assert_not_in_group(fn() => get_discussion_data::execute($fixture->pendingb->approval_token));

        $data = get_discussion_data::execute($fixture->pendinga->approval_token);
        $this->assertNotEmpty($data['posts']);
        // Pre-existing fullname() debugging of the post authors, unrelated to groups.
        $this->resetDebugging();
    }

    /**
     * update_response refuses a token of another group's discussion and keeps the message.
     */
    public function test_update_response_rejects_other_group_token(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->create_fixture();

        $this->setUser($fixture->teachera);

        $this->assert_not_in_group(fn() => update_response::execute($fixture->pendingb->approval_token, 'Tampered'));

        $this->assertSame(
            $fixture->pendingb->message,
            $DB->get_field('local_forum_ai_pending', 'message', ['id' => $fixture->pendingb->id], MUST_EXIST)
        );
    }

    /**
     * Approving or rejecting another group's token creates no post and keeps the row pending.
     */
    public function test_approve_and_reject_reject_other_group_token(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->create_fixture();

        $postcount = $DB->count_records('forum_posts', ['discussion' => $fixture->discussionb->id]);

        $this->setUser($fixture->teachera);

        foreach (['approve', 'reject'] as $action) {
            $this->assert_not_in_group(fn() => approve_response::execute($fixture->pendingb->approval_token, $action));

            $row = $DB->get_record('local_forum_ai_pending', ['id' => $fixture->pendingb->id], '*', MUST_EXIST);
            $this->assertSame('pending', $row->status);
            $this->assertEmpty($row->postid);
            $this->assertEmpty($row->action_userid);
            $this->assertSame($postcount, $DB->count_records('forum_posts', ['discussion' => $fixture->discussionb->id]));
        }
    }

    /**
     * Manual approval never publishes, on behalf of $USER, where core forbids them to reply.
     */
    public function test_manual_approval_cannot_publish_in_other_group_discussion(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->create_fixture();

        $this->setUser($fixture->teachera);

        // The shared publisher refuses a group B discussion even when the author is $USER.
        $postcount = $DB->count_records('forum_posts', ['discussion' => $fixture->discussionb->id]);
        $result = approval::publish_ai_post(
            $fixture->discussionb,
            $fixture->forum,
            $fixture->cm,
            $fixture->course,
            $fixture->pendingb,
            (int) $fixture->discussionb->firstpost,
            (int) $fixture->teachera->id
        );
        $this->assertFalse($result);
        $this->assertDebuggingCalled();
        $this->assertSame($postcount, $DB->count_records('forum_posts', ['discussion' => $fixture->discussionb->id]));
        $this->assertEmpty($DB->get_field('local_forum_ai_pending', 'postid', ['id' => $fixture->pendingb->id]));

        // In separate groups, core forbids non-accessallgroups users from replying to
        // discussions for all participants: approval fails cleanly and keeps the row.
        $postcount = $DB->count_records('forum_posts', ['discussion' => $fixture->discussionall->id]);
        try {
            approve_response::execute($fixture->pendingall->approval_token, 'approve');
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_cannotpublishingroup', $e->errorcode);
        }
        $row = $DB->get_record('local_forum_ai_pending', ['id' => $fixture->pendingall->id], '*', MUST_EXIST);
        $this->assertSame('pending', $row->status);
        $this->assertEmpty($row->postid);
        $this->assertSame($postcount, $DB->count_records('forum_posts', ['discussion' => $fixture->discussionall->id]));

        // The own group's discussion is still published normally.
        $result = approve_response::execute($fixture->pendinga->approval_token, 'approve');
        $this->assertTrue($result['success']);
    }

    /**
     * The approval notification only reaches approvers who can access the discussion group.
     */
    public function test_notification_not_sent_to_approver_outside_discussion_group(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $fixture = $this->create_fixture();

        $teacherb = $this->getDataGenerator()->create_and_enrol($fixture->course, 'teacher');
        $this->getDataGenerator()->create_group_member(['groupid' => $fixture->groupb->id, 'userid' => $teacherb->id]);

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $pendingid = approval::create_approval_request(
            $fixture->discussionb,
            $fixture->forum,
            '<p>AI answer for group B</p>'
        );
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertGreaterThan(0, $pendingid);
        $recipients = array_map(static fn($message) => (int) $message->useridto, $messages);
        $this->assertContains((int) $teacherb->id, $recipients);
        $this->assertContains((int) $fixture->editingteacher->id, $recipients);
        $this->assertNotContains((int) $fixture->teachera->id, $recipients);
    }

    /**
     * Approvers who could not publish in an all-participants discussion are not notified about it.
     */
    public function test_notification_not_sent_for_all_participants_discussion_to_group_limited_approver(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $fixture = $this->create_fixture();

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $pendingid = approval::create_approval_request(
            $fixture->discussionall,
            $fixture->forum,
            '<p>AI answer for all participants</p>'
        );
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertGreaterThan(0, $pendingid);
        $recipients = array_map(static fn($message) => (int) $message->useridto, $messages);
        $this->assertContains((int) $fixture->editingteacher->id, $recipients);
        $this->assertNotContains((int) $fixture->teachera->id, $recipients);
    }

    /**
     * The manual review payload excludes the posts of discussions outside the caller's groups.
     */
    public function test_review_payload_excludes_posts_from_other_group_discussions(): void {
        $this->resetAfterTest();
        $fixture = $this->create_fixture();

        // A student in both groups posts in a group A and a group B discussion.
        $studentab = $this->getDataGenerator()->create_and_enrol($fixture->course, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $fixture->groupa->id, 'userid' => $studentab->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $fixture->groupb->id, 'userid' => $studentab->id]);
        $owna = $this->create_discussion($fixture, $fixture->forum, $studentab, (int) $fixture->groupa->id);
        $ownb = $this->create_discussion($fixture, $fixture->forum, $studentab, (int) $fixture->groupb->id);
        $ownall = $this->create_discussion($fixture, $fixture->forum, $studentab, -1);

        $client = new mock_ai_client(['feedback' => 'Mock AI feedback']);
        ai_service::set_client_for_testing($client);

        $this->setUser($fixture->teachera);
        process_review::execute((int) $fixture->cm->id, (int) $studentab->id);

        $request = $client->last_request();
        $this->assertNotNull($request);
        $ids = array_map(
            static fn($entry) => (int) $entry['discussion_id'],
            $request['body']['forum_participations'][0]['participation']['discussions']
        );
        $this->assertContains((int) $owna->id, $ids);
        $this->assertContains((int) $ownall->id, $ids);
        $this->assertNotContains((int) $ownb->id, $ids);

        // Without a viewer the payload keeps every discussion of the student.
        $payload = utils::build_forum_ai_payload((int) $fixture->cm->id, (int) $studentab->id);
        $ids = array_map(
            static fn($entry) => (int) $entry['discussion_id'],
            $payload['forum_participations'][0]['participation']['discussions']
        );
        $this->assertContains((int) $ownb->id, $ids);
    }

    /**
     * The central token loader used by review.php refuses another group's token.
     */
    public function test_review_loader_rejects_other_group_token(): void {
        $this->resetAfterTest();
        $fixture = $this->create_fixture();

        $this->setUser($fixture->teachera);

        $this->assert_not_in_group(
            fn() => approval::load_pending_for_user($fixture->pendingb->approval_token, 'pending', IGNORE_MISSING)
        );

        $loaded = approval::load_pending_for_user($fixture->pendinga->approval_token, 'pending', IGNORE_MISSING);
        $this->assertSame((int) $fixture->pendinga->id, (int) $loaded->pending->id);
        $this->assertSame((int) $fixture->cm->id, (int) $loaded->cm->id);

        // A token that is no longer pending yields null instead of an exception.
        $this->assertNull(approval::load_pending_for_user('missingtoken', 'pending', IGNORE_MISSING));
    }

    /**
     * Asserts that the callback is refused with error_discussionnotingroup.
     *
     * @param callable $callback Callback expected to throw.
     * @return void
     */
    private function assert_not_in_group(callable $callback): void {
        try {
            $callback();
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_discussionnotingroup', $e->errorcode);
        }
    }

    /**
     * Creates a discussion in a forum.
     *
     * @param stdClass $fixture Fixture from create_fixture().
     * @param stdClass $forum Forum record.
     * @param stdClass $user Author.
     * @param int $groupid Discussion group (-1 for all participants).
     * @return stdClass Discussion record.
     */
    private function create_discussion(stdClass $fixture, stdClass $forum, stdClass $user, int $groupid): stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $fixture->course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'groupid' => $groupid,
        ]);
    }

    /**
     * Creates a separate-groups forum with groups A and B and pending rows in each group.
     *
     * teachera is a non-editing teacher, member of group A only, granted the
     * approval capability and prohibited from accessing all groups.
     *
     * @return stdClass
     */
    private function create_fixture(): stdClass {
        global $DB;

        $generator = $this->getDataGenerator();
        $fixture = new stdClass();
        $fixture->course = $generator->create_course();
        $fixture->forum = $generator->create_module('forum', [
            'course' => $fixture->course->id,
            'groupmode' => SEPARATEGROUPS,
        ]);
        $fixture->cm = get_coursemodule_from_instance('forum', $fixture->forum->id, $fixture->course->id, false, MUST_EXIST);
        $fixture->groupa = $generator->create_group(['courseid' => $fixture->course->id]);
        $fixture->groupb = $generator->create_group(['courseid' => $fixture->course->id]);

        $coursecontextid = \context_course::instance($fixture->course->id)->id;
        $teacherroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability('local/forum_ai:approveresponses', CAP_ALLOW, $teacherroleid, $coursecontextid, true);
        assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $teacherroleid, $coursecontextid, true);

        $fixture->teachera = $generator->create_and_enrol($fixture->course, 'teacher');
        $fixture->editingteacher = $generator->create_and_enrol($fixture->course, 'editingteacher');
        $fixture->studenta = $generator->create_and_enrol($fixture->course, 'student');
        $fixture->studentb = $generator->create_and_enrol($fixture->course, 'student');
        $generator->create_group_member(['groupid' => $fixture->groupa->id, 'userid' => $fixture->teachera->id]);
        $generator->create_group_member(['groupid' => $fixture->groupa->id, 'userid' => $fixture->studenta->id]);
        $generator->create_group_member(['groupid' => $fixture->groupb->id, 'userid' => $fixture->studentb->id]);

        $fixture->discussiona = $this->create_discussion(
            $fixture,
            $fixture->forum,
            $fixture->studenta,
            (int) $fixture->groupa->id
        );
        $fixture->discussionb = $this->create_discussion(
            $fixture,
            $fixture->forum,
            $fixture->studentb,
            (int) $fixture->groupb->id
        );
        $fixture->discussionall = $this->create_discussion($fixture, $fixture->forum, $fixture->studenta, -1);

        $plugingenerator = $generator->get_plugin_generator('local_forum_ai');
        $fixture->pendinga = $plugingenerator->create_pending_response(['discussionid' => $fixture->discussiona->id]);
        $fixture->pendingb = $plugingenerator->create_pending_response(['discussionid' => $fixture->discussionb->id]);
        $fixture->pendingall = $plugingenerator->create_pending_response(['discussionid' => $fixture->discussionall->id]);

        return $fixture;
    }
}
