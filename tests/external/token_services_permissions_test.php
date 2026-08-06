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
 * Tests for the approval capability requirement in the token-based external functions.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use externallib_advanced_testcase;
use local_forum_ai\xss_payload_fixture;
use local_forum_ai\utils;
use moodle_exception;
use required_capability_exception;
use stdClass;

global $CFG;

require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once(__DIR__ . '/../fixtures/xss_payload_fixture.php');

/**
 * Tests that approve_response, get_details and update_response require the approval capability.
 *
 * Covers: MDL-INT-024 — Permisos y validacion de contexto en los servicios web
 * Covers: MDL-INT-019 — Pagina de respuestas pendientes de aprobacion
 * Covers: MDL-INT-025 — Sanitizacion del contenido de la IA almacenado y publicado
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\external\approve_response
 * @covers \local_forum_ai\external\get_details
 * @covers \local_forum_ai\external\get_discussion_data
 * @covers \local_forum_ai\external\update_response
 */
final class token_services_permissions_test extends externallib_advanced_testcase {
    /**
     * A student holding a valid token must not be able to approve or reject the response.
     */
    public function test_student_cannot_approve_or_reject(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, $student] = $this->create_pending_response();

        $this->setUser($student);

        foreach (['approve', 'reject'] as $action) {
            try {
                approve_response::execute($pending->approval_token, $action);
                $this->fail("Expected required_capability_exception was not thrown for action '{$action}'.");
            } catch (required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }

            // The pending row must remain untouched.
            $row = $DB->get_record('local_forum_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
            $this->assertSame('pending', $row->status);
            $this->assertSame($pending->message, $row->message);
        }
    }

    /**
     * A student holding a valid token must not be able to read the discussion details.
     */
    public function test_student_cannot_get_details(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, $student] = $this->create_pending_response();

        $this->setUser($student);

        try {
            get_details::execute($pending->approval_token);
            $this->fail('Expected required_capability_exception was not thrown.');
        } catch (required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $row = $DB->get_record('local_forum_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertSame('pending', $row->status);
        $this->assertSame($pending->message, $row->message);
    }

    /**
     * A student holding a valid token must not be able to read the discussion data.
     */
    public function test_student_cannot_get_discussion_data(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, $student] = $this->create_pending_response();

        $this->setUser($student);

        try {
            get_discussion_data::execute($pending->approval_token);
            $this->fail('Expected required_capability_exception was not thrown.');
        } catch (required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $row = $DB->get_record('local_forum_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertSame('pending', $row->status);
        $this->assertSame($pending->message, $row->message);
    }

    /**
     * A student holding a valid token must not be able to rewrite the pending message.
     */
    public function test_student_cannot_update_response(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, $student] = $this->create_pending_response();

        $this->setUser($student);

        try {
            update_response::execute($pending->approval_token, 'Tampered message');
            $this->fail('Expected required_capability_exception was not thrown.');
        } catch (required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $row = $DB->get_record('local_forum_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertSame('pending', $row->status);
        $this->assertSame($pending->message, $row->message);
    }

    /**
     * A teacher with the approval capability can read the details of a pending response.
     */
    public function test_teacher_can_get_details_of_pending(): void {
        $this->resetAfterTest();

        [$pending, $student, $teacher] = $this->create_pending_response();

        $this->setUser($teacher);

        $result = get_details::execute($pending->approval_token);
        // Pre-existing behaviour: get_details builds author names from partial user records,
        // which raises one missing-name-fields debugging notice per post (two posts in the fixture).
        $this->assertDebuggingCalledCount(2);
        $result = external_api::clean_returnvalue(get_details::execute_returns(), $result);

        $this->assertSame('pending', $result['status']);
        $this->assertSame($pending->approval_token, $result['token']);

        // The editor field carries the stored source; airesponse stays the rendered variant.
        $this->assertSame($pending->message, $result['airesponseraw']);
        $this->assertSame(format_text($pending->message, FORMAT_HTML), $result['airesponse']);
    }

    /**
     * get_details must keep working for non-pending records (history modal contract).
     */
    public function test_teacher_can_get_details_of_approved(): void {
        $this->resetAfterTest();

        [$pending, $student, $teacher] = $this->create_pending_response('approved');

        $this->setUser($teacher);

        $result = get_details::execute($pending->approval_token);
        // Same pre-existing missing-name-fields debugging notices as in the pending case.
        $this->assertDebuggingCalledCount(2);
        $result = external_api::clean_returnvalue(get_details::execute_returns(), $result);

        $this->assertSame('approved', $result['status']);
        $this->assertSame($pending->message, $result['airesponseraw']);
    }

    /**
     * A get_details/update_response round-trip must not mutate an already-clean message.
     *
     * This pins the no-op-save contract of the pending modal: the edit textarea
     * is filled from airesponseraw, so saving without editing keeps the stored
     * message byte-identical (no filter-rendered markup is ever written back).
     */
    public function test_noop_edit_roundtrip_preserves_stored_message(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, $student, $teacher] = $this->create_pending_response();

        // Seed an already-clean stored message (a fixed point of the purifier).
        $clean = clean_text('<p>Hola <strong>mundo</strong></p><ul><li>a</li></ul>', FORMAT_HTML);
        $DB->set_field('local_forum_ai_pending', 'message', $clean, ['id' => $pending->id]);

        $this->setUser($teacher);

        $details = get_details::execute($pending->approval_token);
        // Same pre-existing missing-name-fields debugging notices as in the other get_details cases.
        $this->assertDebuggingCalledCount(2);
        $details = external_api::clean_returnvalue(get_details::execute_returns(), $details);
        $this->assertSame($clean, $details['airesponseraw']);

        // Saving the editor content unchanged keeps the stored message byte-identical.
        $result = update_response::execute($pending->approval_token, $details['airesponseraw']);
        $result = external_api::clean_returnvalue(update_response::execute_returns(), $result);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(
            $clean,
            $DB->get_field('local_forum_ai_pending', 'message', ['id' => $pending->id], MUST_EXIST)
        );
    }

    /**
     * A teacher with the approval capability can read the discussion data for a token.
     */
    public function test_teacher_can_get_discussion_data(): void {
        $this->resetAfterTest();

        [$pending, $student, $teacher] = $this->create_pending_response();

        $this->setUser($teacher);

        $result = get_discussion_data::execute($pending->approval_token);
        // Same pre-existing missing-name-fields debugging notices as in the get_details cases.
        $this->assertDebuggingCalledCount(2);
        $result = external_api::clean_returnvalue(get_discussion_data::execute_returns(), $result);

        $this->assertCount(2, $result['posts']);
        $this->assertStringContainsString($pending->message, $result['airesponse']);
    }

    /**
     * Detail services must filter deleted and private posts in the same way.
     */
    public function test_detail_services_exclude_hidden_posts_consistently(): void {
        $this->resetAfterTest();

        [$pending, $discussion, , $teacher] = $this->create_pending_response_with_hidden_posts();

        $this->setUser($teacher);

        $details = external_api::clean_returnvalue(
            get_details::execute_returns(),
            get_details::execute($pending->approval_token)
        );
        $discussiondata = external_api::clean_returnvalue(
            get_discussion_data::execute_returns(),
            get_discussion_data::execute($pending->approval_token)
        );

        $this->assertDebuggingCalledCount(6);

        $this->assertSame(
            array_column($details['posts'], 'subject'),
            array_column($discussiondata['posts'], 'subject')
        );
        $this->assertSame(
            array_column($details['posts'], 'message'),
            array_column($discussiondata['posts'], 'message')
        );

        $messages = implode(' | ', array_column($details['posts'], 'message'));
        $this->assertStringContainsString('Root discussion topic', $messages);
        $this->assertStringContainsString('Visible classmate reply', $messages);
        $this->assertStringContainsString('Current visible reply', $messages);
        $this->assertStringNotContainsString('Deleted moderation target', $messages);
        $this->assertStringNotContainsString('Private guidance for one student only', $messages);

        $context = utils::build_thread_context((int) $discussion->id, (int) $pending->parentpostid);
        $contextmessages = implode(' | ', array_column($context, 'message'));
        $this->assertStringContainsString('Root discussion topic', $contextmessages);
        $this->assertStringContainsString('Visible classmate reply', $contextmessages);
        $this->assertStringNotContainsString('Deleted moderation target', $contextmessages);
        $this->assertStringNotContainsString('Private guidance for one student only', $contextmessages);
    }

    /**
     * A teacher with the approval capability can edit a pending response.
     */
    public function test_teacher_can_update_pending_response(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, $student, $teacher] = $this->create_pending_response();

        $this->setUser($teacher);

        $result = update_response::execute($pending->approval_token, 'Edited by the teacher');
        $result = external_api::clean_returnvalue(update_response::execute_returns(), $result);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(
            'Edited by the teacher',
            $DB->get_field('local_forum_ai_pending', 'message', ['id' => $pending->id], MUST_EXIST)
        );
    }

    /**
     * Already approved or rejected responses can no longer be edited.
     */
    public function test_teacher_cannot_update_non_pending_response(): void {
        global $DB;

        $this->resetAfterTest();

        foreach (['approved', 'rejected'] as $status) {
            [$pending, $student, $teacher] = $this->create_pending_response($status);

            $this->setUser($teacher);

            try {
                update_response::execute($pending->approval_token, 'Tampered message');
                $this->fail("Expected moodle_exception was not thrown for status '{$status}'.");
            } catch (moodle_exception $e) {
                $this->assertSame('error_responsenotpending', $e->errorcode);
            }

            $this->assertSame(
                $pending->message,
                $DB->get_field('local_forum_ai_pending', 'message', ['id' => $pending->id], MUST_EXIST)
            );
        }
    }

    /**
     * A teacher with the approval capability can approve a pending response in an unlocked discussion.
     */
    public function test_teacher_can_approve_pending_response(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, $student, $teacher] = $this->create_pending_response();

        $this->setUser($teacher);

        $postcount = $DB->count_records('forum_posts');

        $result = approve_response::execute($pending->approval_token, 'approve');
        $result = external_api::clean_returnvalue(approve_response::execute_returns(), $result);

        $this->assertTrue($result['success']);
        $this->assertSame($postcount + 1, $DB->count_records('forum_posts'));
        $this->assertSame(
            'approved',
            $DB->get_field('local_forum_ai_pending', 'status', ['id' => $pending->id], MUST_EXIST)
        );
    }

    /**
     * Approving a legacy dirty pending row must publish a cleaned, untrusted post.
     */
    public function test_approve_publishes_sanitized_legacy_message(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, $student, $teacher] = $this->create_pending_response();

        // Simulate a legacy row stored before sanitization existed.
        $DB->set_field('local_forum_ai_pending', 'message', xss_payload_fixture::PAYLOAD, ['id' => $pending->id]);

        $this->setUser($teacher);

        $result = approve_response::execute($pending->approval_token, 'approve');
        $result = external_api::clean_returnvalue(approve_response::execute_returns(), $result);
        $this->assertTrue($result['success']);

        // The publication succeeds and the new post is neutralized, not rejected.
        $post = $DB->get_record('forum_posts', ['userid' => $teacher->id], '*', MUST_EXIST);
        $this->assertStringNotContainsString('<script', $post->message);
        $this->assertStringNotContainsString('onerror', $post->message);
        $this->assertStringContainsString('<p>', $post->message);
        $this->assertStringContainsString('<strong>', $post->message);
        $this->assertEquals(0, $post->messagetrust);
    }

    /**
     * The edit service must store and return purified HTML.
     */
    public function test_update_response_sanitizes_message(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, $student, $teacher] = $this->create_pending_response();

        $this->setUser($teacher);

        $result = update_response::execute($pending->approval_token, xss_payload_fixture::PAYLOAD);
        $result = external_api::clean_returnvalue(update_response::execute_returns(), $result);

        $this->assertSame('ok', $result['status']);

        $stored = $DB->get_field('local_forum_ai_pending', 'message', ['id' => $pending->id], MUST_EXIST);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringContainsString('<p>', $stored);
        $this->assertStringContainsString('<strong>', $stored);
        $this->assertSame($stored, $result['message']);
    }

    /**
     * A teacher with the approval capability can reject a pending response.
     */
    public function test_teacher_can_reject_pending_response(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, $student, $teacher] = $this->create_pending_response();

        $this->setUser($teacher);

        $result = approve_response::execute($pending->approval_token, 'reject');
        $result = external_api::clean_returnvalue(approve_response::execute_returns(), $result);

        $this->assertTrue($result['success']);
        $this->assertSame(
            'rejected',
            $DB->get_field('local_forum_ai_pending', 'status', ['id' => $pending->id], MUST_EXIST)
        );
    }

    /**
     * Creates an unlocked discussion with an AI response row holding a valid token.
     *
     * @param string $status Status of the pending row ('pending', 'approved' or 'rejected').
     * @return array [$pending, $student, $teacher].
     */
    private function create_pending_response(string $status = 'pending'): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
        ]);
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $reply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
        ]);

        $pending = new stdClass();
        $pending->discussionid = $discussion->id;
        $pending->forumid = $forum->id;
        $pending->parentpostid = $reply->id;
        $pending->creator_userid = $student->id;
        $pending->subject = 'Re: ' . $discussion->name;
        $pending->message = 'AI-generated reply under review.';
        $pending->status = $status;
        $pending->approval_token = md5(uniqid('tokentest_', true));
        $pending->timecreated = time();
        $pending->timemodified = time();
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);

        return [$pending, $student, $teacher];
    }

    /**
     * Creates a discussion containing visible, deleted and private posts.
     *
     * @return array [$pending, $discussion, $student, $teacher].
     */
    private function create_pending_response_with_hidden_posts(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
            'message' => 'Root discussion topic',
        ]);
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $visiblereply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
            'message' => 'Visible classmate reply',
        ]);
        $deletedreply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
            'message' => 'Deleted moderation target',
        ]);
        $DB->set_field('forum_posts', 'deleted', 1, ['id' => $deletedreply->id]);

        $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $visiblereply->id,
            'userid' => $teacher->id,
            'privatereplyto' => $student->id,
            'message' => 'Private guidance for one student only',
        ]);

        $currentpost = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $visiblereply->id,
            'userid' => $student->id,
            'message' => 'Current visible reply',
        ]);

        $pending = new stdClass();
        $pending->discussionid = $discussion->id;
        $pending->forumid = $forum->id;
        $pending->parentpostid = $currentpost->id;
        $pending->creator_userid = $student->id;
        $pending->subject = 'Re: ' . $discussion->name;
        $pending->message = 'AI-generated reply under review.';
        $pending->status = 'pending';
        $pending->approval_token = md5(uniqid('tokentest_', true));
        $pending->timecreated = time();
        $pending->timemodified = time();
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);

        return [$pending, $discussion, $student, $teacher];
    }
}
