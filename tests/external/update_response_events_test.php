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
 * Tests for the audit event triggered by the update_response external function.
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
use moodle_exception;
use required_capability_exception;
use stdClass;

global $CFG;

require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests that editing a pending response triggers an audit event and stays capability-gated.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\external\update_response
 * @covers \local_forum_ai\event\response_updated
 */
final class update_response_events_test extends externallib_advanced_testcase {
    /**
     * Editing must trigger exactly one response_updated event with full audit data.
     */
    public function test_teacher_edit_triggers_response_updated_event(): void {
        $this->resetAfterTest();

        [$pending, $student, $teacher, , $cm] = $this->create_pending_response();

        $this->setUser($teacher);

        $sink = $this->redirectEvents();
        $result = update_response::execute($pending->approval_token, '<p>Edited by the teacher.</p>');
        $events = $sink->get_events();
        $sink->close();

        $result = external_api::clean_returnvalue(update_response::execute_returns(), $result);
        $this->assertSame('ok', $result['status']);

        $updatedevents = $this->filter_updated_events($events);
        $this->assertCount(1, $updatedevents);

        $event = $updatedevents[0];
        $this->assertEquals($teacher->id, $event->userid);
        $this->assertEquals($pending->id, $event->objectid);
        $this->assertSame('local_forum_ai_pending', $event->objecttable);
        $this->assertEquals($student->id, $event->relateduserid);
        $this->assertEquals(\context_module::instance($cm->id)->id, $event->contextid);
        $this->assertEquals($pending->forumid, $event->other['forumid']);
        $this->assertEquals($pending->discussionid, $event->other['discussionid']);
        $this->assertNotEmpty($event->get_name());
        $this->assertStringContainsString((string) $pending->id, $event->get_description());
    }

    /**
     * A user without the approval capability must be rejected before any event is triggered.
     */
    public function test_student_without_capability_cannot_edit_and_no_event_is_triggered(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, $student] = $this->create_pending_response();

        $this->setUser($student);

        $sink = $this->redirectEvents();
        try {
            update_response::execute($pending->approval_token, 'Tampered message');
            $this->fail('Expected required_capability_exception was not thrown.');
        } catch (required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(0, $this->filter_updated_events($events));
        $this->assertSame(
            $pending->message,
            $DB->get_field('local_forum_ai_pending', 'message', ['id' => $pending->id], MUST_EXIST)
        );
    }

    /**
     * A response that is no longer pending must not be editable and must trigger no event.
     */
    public function test_editing_an_approved_response_is_rejected_without_event(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, , $teacher] = $this->create_pending_response();
        $DB->set_field('local_forum_ai_pending', 'status', 'approved', ['id' => $pending->id]);

        $this->setUser($teacher);

        $sink = $this->redirectEvents();
        try {
            update_response::execute($pending->approval_token, 'Late edit');
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_responsenotpending', $e->errorcode);
        }
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(0, $this->filter_updated_events($events));
        $this->assertSame(
            $pending->message,
            $DB->get_field('local_forum_ai_pending', 'message', ['id' => $pending->id], MUST_EXIST)
        );
    }

    /**
     * Keeps only the response_updated events captured by a sink.
     *
     * @param \core\event\base[] $events Events captured by the sink.
     * @return \local_forum_ai\event\response_updated[]
     */
    private function filter_updated_events(array $events): array {
        return array_values(array_filter($events, static function ($event): bool {
            return $event instanceof \local_forum_ai\event\response_updated;
        }));
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
        $pending->approval_token = md5(uniqid('updateeventstest_', true));
        $pending->timecreated = time();
        $pending->timemodified = time();
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);

        return [$pending, $student, $teacher, $course, $cm];
    }
}
