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
 * Tests for delayed Forum AI queue processing.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

use stdClass;

/**
 * Regression tests for the delayed queue processor.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\task\process_ai_queue
 * @covers \local_forum_ai\observer\post
 */
final class process_ai_queue_test extends \advanced_testcase {
    /**
     * Deferred processing must preserve the original author identity and match the immediate path.
     */
    public function test_deferred_processing_matches_immediate_task_identity(): void {
        global $DB;

        $this->resetAfterTest();

        [$forum, $discussion, $course, $cm, $student, $teacher] = $this->create_forum_and_discussion();

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $post = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $teacher->id,
            'message' => 'Deferred reply awaiting queue processing',
        ]);

        $event = \mod_forum\event\post_created::create([
            'context' => \context_module::instance($cm->id),
            'objectid' => $post->id,
            'other' => [
                'discussionid' => $discussion->id,
                'forumid' => $forum->id,
                'forumtype' => $forum->type,
            ],
        ]);

        $this->set_forum_config($forum->id, ['require_approval' => 1, 'usedelay' => 0]);

        observer\post::post_created($event);
        $immediate = $this->get_single_queued_task();

        $this->assertSame((int) $teacher->id, (int) $immediate->userid);

        $DB->delete_records('task_adhoc');

        $queueid = $this->create_queue_row('post', [
            'postid' => $post->id,
            'cmid' => $cm->id,
        ]);

        $task = new task\process_ai_queue();
        $task->execute();

        $deferred = $this->get_single_queued_task();

        $this->assertSame((int) $teacher->id, (int) $deferred->userid);
        $this->assertSame($immediate->classname, $deferred->classname);
        $this->assertSame($immediate->component, $deferred->component);
        $this->assertSame($immediate->customdata, $deferred->customdata);
        $this->assertFalse($DB->record_exists('local_forum_ai_queue', ['id' => $queueid]));
    }

    /**
     * Double execution against the same queued row must still dispatch only once.
     */
    public function test_double_execute_dispatches_only_once(): void {
        global $DB;

        $this->resetAfterTest();

        [$forum, $discussion, $course, $cm, $student, $teacher] = $this->create_forum_and_discussion();

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $post = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $teacher->id,
            'message' => 'Locked queue reply',
        ]);

        $queueid = $this->create_queue_row('post', [
            'postid' => $post->id,
            'cmid' => $cm->id,
        ]);

        $task = new task\process_ai_queue();
        $task->execute();

        $this->assertSame(1, $DB->count_records('task_adhoc', ['component' => 'local_forum_ai']));
        $this->assertFalse($DB->record_exists('local_forum_ai_queue', ['id' => $queueid]));

        $task->execute();

        $queuedtasks = $DB->get_records('task_adhoc', ['component' => 'local_forum_ai']);
        $this->assertCount(1, $queuedtasks);
        $queuedtask = reset($queuedtasks);
        $this->assertSame((int) $teacher->id, (int) $queuedtask->userid);
        $this->assertFalse($DB->record_exists('local_forum_ai_queue', ['id' => $queueid]));
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
     * Creates a course, a forum and one discussion started by a student.
     *
     * @return array [$forum, $discussion, $course, $cm, $student, $teacher].
     */
    private function create_forum_and_discussion(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forummodule->id, $course->id, false, MUST_EXIST);
        $forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
        ]);
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        return [$forum, $discussion, $course, $cm, $student, $teacher];
    }

    /**
     * Inserts a delayed queue row for a post or discussion.
     *
     * @param string $type Queue item type.
     * @param array $payload Payload data.
     * @return int Queue row id.
     */
    private function create_queue_row(string $type, array $payload): int {
        global $DB;

        $row = (object) [
            'type' => $type,
            'payload' => json_encode((object) $payload),
            'timecreated' => time(),
            'timetoprocess' => time() - 1,
            'processed' => 0,
        ];

        return (int) $DB->insert_record('local_forum_ai_queue', $row);
    }

    /**
     * Gets the single queued adhoc task created by the processor.
     *
     * @return stdClass
     */
    private function get_single_queued_task(): stdClass {
        global $DB;

        $task = $DB->get_record('task_adhoc', ['component' => 'local_forum_ai'], '*', MUST_EXIST);
        $this->assertSame('\\local_forum_ai\\task\\process_ai_post', $task->classname);

        return $task;
    }
}
