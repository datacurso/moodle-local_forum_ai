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
 * Tests for the audit events and access rules of the approve/reject external function.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use externallib_advanced_testcase;
use required_capability_exception;
use stdClass;

global $CFG;

require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests that approving/rejecting triggers audit events and stays capability-gated.
 *
 * Covers: FORUMAI-SEC-002 — approval/rejection audit trail and access control.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\external\approve_response
 * @covers \local_forum_ai\event\response_approved
 * @covers \local_forum_ai\event\response_rejected
 */
final class approve_response_events_test extends externallib_advanced_testcase {
    /**
     * A user whose role only grants mod/forum:viewdiscussion must not approve or reject.
     */
    public function test_viewdiscussion_only_role_cannot_approve_or_reject(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, , , $course, $cm] = $this->create_pending_response();

        // Custom role granting ONLY mod/forum:viewdiscussion, assigned in the course context.
        $coursecontext = \context_course::instance($course->id);
        $modulecontext = \context_module::instance($cm->id);
        $roleid = create_role('AI viewer', 'aiviewer', 'Can only view discussions');
        assign_capability('mod/forum:viewdiscussion', CAP_ALLOW, $roleid, $coursecontext->id, true);

        $viewer = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($viewer->id, $course->id);
        role_assign($roleid, $viewer->id, $coursecontext->id);

        // The capability must hold in the module context, but the approval one must not.
        $this->assertTrue(has_capability('mod/forum:viewdiscussion', $modulecontext, $viewer));
        $this->assertFalse(has_capability('local/forum_ai:approveresponses', $modulecontext, $viewer));

        $this->setUser($viewer);

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
        }
    }

    /**
     * Approving must trigger exactly one response_approved event with full audit data.
     */
    public function test_approve_triggers_response_approved_event(): void {
        $this->resetAfterTest();

        [$pending, $student, $teacher, , $cm] = $this->create_pending_response();

        $this->setUser($teacher);

        $sink = $this->redirectEvents();
        $result = approve_response::execute($pending->approval_token, 'approve');
        $events = $sink->get_events();
        $sink->close();

        $result = external_api::clean_returnvalue(approve_response::execute_returns(), $result);
        $this->assertTrue($result['success']);

        // The sink also captures core events (post_created): filter by class.
        $approvedevents = array_values(array_filter($events, static function ($event): bool {
            return $event instanceof \local_forum_ai\event\response_approved;
        }));
        $this->assertCount(1, $approvedevents);

        $event = $approvedevents[0];
        $this->assertEquals($teacher->id, $event->userid);
        $this->assertEquals($pending->id, $event->objectid);
        $this->assertSame('local_forum_ai_pending', $event->objecttable);
        $this->assertEquals($student->id, $event->relateduserid);
        $this->assertEquals(\context_module::instance($cm->id)->id, $event->contextid);
        $this->assertEquals($pending->forumid, $event->other['forumid']);
        $this->assertEquals($pending->discussionid, $event->other['discussionid']);
    }

    /**
     * Rejecting must trigger exactly one response_rejected event and create no post.
     */
    public function test_reject_triggers_response_rejected_event_without_post(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, $student, $teacher, , $cm] = $this->create_pending_response();

        $this->setUser($teacher);

        $postcount = $DB->count_records('forum_posts');

        $sink = $this->redirectEvents();
        $result = approve_response::execute($pending->approval_token, 'reject');
        $events = $sink->get_events();
        $sink->close();

        $result = external_api::clean_returnvalue(approve_response::execute_returns(), $result);
        $this->assertTrue($result['success']);

        // No forum post may be published on rejection.
        $this->assertSame($postcount, $DB->count_records('forum_posts'));

        $rejectedevents = array_values(array_filter($events, static function ($event): bool {
            return $event instanceof \local_forum_ai\event\response_rejected;
        }));
        $this->assertCount(1, $rejectedevents);

        $event = $rejectedevents[0];
        $this->assertEquals($teacher->id, $event->userid);
        $this->assertEquals($pending->id, $event->objectid);
        $this->assertSame('local_forum_ai_pending', $event->objecttable);
        $this->assertEquals($student->id, $event->relateduserid);
        $this->assertEquals(\context_module::instance($cm->id)->id, $event->contextid);
        $this->assertEquals($pending->forumid, $event->other['forumid']);
        $this->assertEquals($pending->discussionid, $event->other['discussionid']);
    }

    /**
     * The AJAX entry point must enforce the sesskey before executing the function.
     *
     * Mechanism verified in core (lib/external/classes/external_api.php,
     * call_external_function): for a loginrequired function outside WS_SERVER, core
     * calls require_sesskey() for the logged-in user. require_sesskey() delegates to
     * confirm_sesskey(), which reads required_param('sesskey') from the request, so
     * a request without a sesskey parameter fails with 'missingparam', a wrong value
     * fails with 'invalidsesskey', and call_external_function reports both through
     * its error/exception envelope instead of letting the exception escape. The
     * PHPUNIT_TEST guard in core only skips the NO_MOODLE_COOKIES shortcut; the
     * sesskey check itself DOES run under PHPUnit.
     */
    public function test_ajax_call_requires_valid_sesskey(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, , $teacher] = $this->create_pending_response();

        $this->setUser($teacher);

        $args = ['token' => $pending->approval_token, 'action' => 'reject'];

        // Without any sesskey parameter the request must fail before execution.
        $response = external_api::call_external_function('local_forum_ai_approve_response', $args);
        $this->assertTrue($response['error']);
        $this->assertSame('missingparam', $response['exception']->errorcode);
        $this->assertSame(
            'pending',
            $DB->get_field('local_forum_ai_pending', 'status', ['id' => $pending->id], MUST_EXIST)
        );

        // A wrong sesskey must be rejected as invalid.
        $_POST['sesskey'] = 'invalidvalue';
        $response = external_api::call_external_function('local_forum_ai_approve_response', $args);
        $this->assertTrue($response['error']);
        $this->assertSame('invalidsesskey', $response['exception']->errorcode);
        $this->assertSame(
            'pending',
            $DB->get_field('local_forum_ai_pending', 'status', ['id' => $pending->id], MUST_EXIST)
        );

        // With the session's real sesskey the same call succeeds.
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function('local_forum_ai_approve_response', $args);
        unset($_POST['sesskey']);

        $this->assertFalse($response['error']);
        $this->assertTrue($response['data']['success']);
        $this->assertSame(
            'rejected',
            $DB->get_field('local_forum_ai_pending', 'status', ['id' => $pending->id], MUST_EXIST)
        );
    }

    /**
     * Creates an unlocked discussion with an AI response row holding a valid token.
     *
     * @return array [$pending, $student, $teacher, $course, $cm].
     */
    private function create_pending_response(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

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
        $pending->status = 'pending';
        $pending->approval_token = md5(uniqid('eventstest_', true));
        $pending->timecreated = time();
        $pending->timemodified = time();
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);

        return [$pending, $student, $teacher, $course, $cm];
    }
}
