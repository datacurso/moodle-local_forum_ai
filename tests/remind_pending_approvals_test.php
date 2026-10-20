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
 * Tests for the scheduled reminder of pending AI approvals.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

use local_forum_ai\task\remind_pending_approvals;
use stdClass;

/**
 * A pending AI response nobody reviews is reminded to the approvers, once every
 * three days, until it is approved or rejected (report 9).
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\task\remind_pending_approvals
 * @covers \local_forum_ai\approval::send_reminder
 */
final class remind_pending_approvals_test extends \advanced_testcase {
    /**
     * An old pending response is reminded to a non-editing teacher, and the reminder is stamped.
     */
    public function test_old_pending_is_reminded_and_stamped(): void {
        global $DB;

        $this->resetAfterTest();
        $f = $this->create_fixture();
        $pending = $this->create_pending($f, ['timecreated' => time() - 4 * DAYSECS]);

        $messages = $this->run_task();

        $this->assertCount(1, $messages);
        $this->assertSame((int) $f->teacher->id, (int) $messages[0]->useridto);
        $this->assertSame('local_forum_ai', $messages[0]->component);
        $this->assertSame('ai_approval_request', $messages[0]->eventtype);
        $this->assertSame(get_string('notification_reminder_subject', 'local_forum_ai'), $messages[0]->subject);
        $this->assertStringContainsString($pending->approval_token, $messages[0]->fullmessage);
        $this->assertEqualsWithDelta(
            time(),
            (int) $DB->get_field('local_forum_ai_pending', 'timeremindered', ['id' => $pending->id]),
            5
        );
    }

    /**
     * A second run right after the first sends nothing: one reminder per interval.
     */
    public function test_second_run_does_not_repeat_the_reminder(): void {
        $this->resetAfterTest();
        $f = $this->create_fixture();
        $this->create_pending($f, ['timecreated' => time() - 4 * DAYSECS]);

        $this->assertCount(1, $this->run_task());
        $this->assertCount(0, $this->run_task());
    }

    /**
     * The reminder is repeated once the interval since the last one has passed.
     */
    public function test_reminder_repeats_after_the_interval(): void {
        $this->resetAfterTest();
        $f = $this->create_fixture();
        $this->create_pending($f, [
            'timecreated' => time() - 10 * DAYSECS,
            'timeremindered' => time() - 4 * DAYSECS,
        ]);

        $this->assertCount(1, $this->run_task());
    }

    /**
     * A response reminded less than the interval ago is left alone.
     */
    public function test_recently_reminded_pending_is_skipped(): void {
        $this->resetAfterTest();
        $f = $this->create_fixture();
        $this->create_pending($f, [
            'timecreated' => time() - 10 * DAYSECS,
            'timeremindered' => time() - DAYSECS,
        ]);

        $this->assertCount(0, $this->run_task());
    }

    /**
     * A recent pending response is not reminded yet.
     */
    public function test_recent_pending_is_not_reminded(): void {
        $this->resetAfterTest();
        $f = $this->create_fixture();
        $this->create_pending($f, ['timecreated' => time() - DAYSECS]);

        $this->assertCount(0, $this->run_task());
    }

    /**
     * Approved and rejected responses are never reminded.
     */
    public function test_resolved_responses_are_not_reminded(): void {
        $this->resetAfterTest();
        $f = $this->create_fixture();
        foreach (['approved', 'rejected', 'expired'] as $status) {
            $this->create_pending($f, ['status' => $status, 'timecreated' => time() - 9 * DAYSECS]);
        }

        $this->assertCount(0, $this->run_task());
    }

    /**
     * Without eligible recipients nothing is stamped, so a later run tries again.
     */
    public function test_no_recipients_leaves_the_reminder_unstamped(): void {
        global $DB;

        $this->resetAfterTest();
        $f = $this->create_fixture(false);
        $pending = $this->create_pending($f, ['timecreated' => time() - 4 * DAYSECS]);

        $this->expectOutputRegex('/no eligible recipients/');
        $this->assertCount(0, $this->run_task());
        $this->assertSame(0, (int) $DB->get_field('local_forum_ai_pending', 'timeremindered', ['id' => $pending->id]));
    }

    /**
     * Nothing is sent while the plugin is switched off.
     */
    public function test_disabled_feature_sends_nothing(): void {
        $this->resetAfterTest();
        $f = $this->create_fixture();
        $this->create_pending($f, ['timecreated' => time() - 4 * DAYSECS]);
        set_config('enableforumai', 0, 'local_forum_ai');

        $this->assertCount(0, $this->run_task());
    }

    /**
     * The task is registered as a daily scheduled task with a readable name.
     */
    public function test_task_is_registered_daily(): void {
        $task = \core\task\manager::get_scheduled_task(remind_pending_approvals::class);

        $this->assertNotFalse($task);
        $this->assertSame(get_string('task_remind_pending_approvals', 'local_forum_ai'), $task->get_name());
        $this->assertSame('*', $task->get_day());
        $this->assertSame('*', $task->get_month());
        $this->assertNotSame('*', $task->get_hour());
    }

    /**
     * Runs the task and returns the messages it sent.
     *
     * @return stdClass[]
     */
    private function run_task(): array {
        $sink = $this->redirectMessages();
        (new remind_pending_approvals())->execute();
        $messages = $sink->get_messages();
        $sink->close();

        return $messages;
    }

    /**
     * Creates a course, a forum discussion and (optionally) a non-editing teacher approver.
     *
     * @param bool $withteacher Whether to enrol a teacher holding the approval capability.
     * @return stdClass Holder (course, forum, discussion, teacher).
     */
    private function create_fixture(bool $withteacher = true): stdClass {
        $generator = $this->getDataGenerator();
        $f = new stdClass();
        $f->course = $generator->create_course();
        $student = $generator->create_and_enrol($f->course, 'student');
        $f->teacher = $withteacher ? $generator->create_and_enrol($f->course, 'teacher') : null;
        $f->forum = $generator->create_module('forum', ['course' => $f->course->id]);
        $f->discussion = $generator->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $f->course->id,
            'forum' => $f->forum->id,
            'userid' => $student->id,
        ]);

        return $f;
    }

    /**
     * Inserts a pending response for the fixture discussion.
     *
     * @param stdClass $f Fixture.
     * @param array $overrides Pending fields to override.
     * @return stdClass Pending row.
     */
    private function create_pending(stdClass $f, array $overrides): stdClass {
        return $this->getDataGenerator()->get_plugin_generator('local_forum_ai')->create_pending_response(
            ['discussionid' => $f->discussion->id] + $overrides
        );
    }
}
