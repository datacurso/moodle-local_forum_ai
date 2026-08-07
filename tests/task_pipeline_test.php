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
 * Tests for the AI processing pipeline of local_forum_ai.
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
 * End-to-end pipeline tests: observers, adhoc tasks, delayed queue and modes.
 *
 * The AI HTTP client is replaced with a stub through the injection seam in
 * ai_service, so no test touches the network.
 *
 * MDL-INT-028 step 3 (Q&A forums: a student who has not posted yet must not
 * see nor receive by mail the AI reply generated for another student) is a
 * browser-visibility concern and is left to Behat coverage.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\task\process_ai_post
 * @covers \local_forum_ai\task\process_ai_discussion
 * @covers \local_forum_ai\task\process_ai_queue
 * @covers \local_forum_ai\task\process_single_forum_discussion
 * @covers \local_forum_ai\observer\post
 * @covers \local_forum_ai\observer\discussion
 */
final class task_pipeline_test extends \advanced_testcase {
    /**
     * Always restore the real AI client after each test.
     */
    protected function tearDown(): void {
        ai_service::set_client_for_testing(null);
        parent::tearDown();
    }

    /**
     * MDL-INT-007: with approval enabled, a reply queues background processing
     * attributed to the posting student and later produces a pending response.
     */
    public function test_reply_in_approval_mode_queues_adhoc_task_with_author_identity(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture([], ['require_approval' => 1]);
        $post = $this->create_reply($fixture, $fixture->student->id, 'Student reply');

        observer\post::post_created($this->build_post_created_event($fixture, (int) $post->id));

        $task = $DB->get_record('task_adhoc', ['component' => 'local_forum_ai'], '*', MUST_EXIST);
        $this->assertSame('\\local_forum_ai\\task\\process_ai_post', $task->classname);
        $this->assertSame((int) $fixture->student->id, (int) $task->userid);
        $customdata = json_decode($task->customdata);
        $this->assertSame((int) $post->id, (int) $customdata->postid);
        $this->assertSame((int) $fixture->cm->id, (int) $customdata->cmid);

        // Running the queued task produces the pending (approval) row.
        $mock = $this->inject_mock(['reply' => 'Pending AI answer']);
        $messagesink = $this->redirectMessages();
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);
        $messagesink->close();

        $pending = $DB->get_record('local_forum_ai_pending', ['forumid' => $fixture->forum->id], '*', MUST_EXIST);
        $this->assertSame('pending', $pending->status);
        $this->assertStringContainsString('Pending AI answer', $pending->message);
        $this->assertCount(1, $mock->requests);
    }

    /**
     * MDL-INT-007: with approval disabled and no delayed review the reply is
     * processed immediately and the AI response is published to the discussion.
     */
    public function test_reply_in_auto_mode_publishes_immediately(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture();
        $this->set_forum_config($fixture->forum->id, [
            'require_approval' => 0,
            'usedelay' => 0,
            'graderid' => $fixture->teacher->id,
        ]);
        $post = $this->create_reply($fixture, $fixture->student->id, 'Please review my answer');

        $mock = $this->inject_mock(['reply' => 'Automatic AI answer']);
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);

        $pending = $DB->get_record('local_forum_ai_pending', ['forumid' => $fixture->forum->id], '*', MUST_EXIST);
        $this->assertSame('approved', $pending->status);
        $this->assertNotEmpty($pending->postid);

        $published = $DB->get_record('forum_posts', ['id' => $pending->postid], '*', MUST_EXIST);
        $this->assertSame((int) $fixture->teacher->id, (int) $published->userid);
        $this->assertStringContainsString('Automatic AI answer', $published->message);

        $this->assertCount(1, $mock->requests);
        $this->assertSame('/forum/chat/v2', $mock->requests[0]['path']);
    }

    /**
     * MDL-INT-007: with delayed review the reply enters the waiting queue with
     * the configured delay instead of being dispatched immediately.
     */
    public function test_reply_in_delayed_mode_inserts_queue_row(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $this->set_forum_config($fixture->forum->id, [
            'require_approval' => 0,
            'usedelay' => 1,
            'delayminutes' => 5,
            'graderid' => $fixture->teacher->id,
        ]);
        $post = $this->create_reply($fixture, $fixture->student->id, 'Delayed reply');

        $before = time();
        observer\post::post_created($this->build_post_created_event($fixture, (int) $post->id));
        $after = time();

        $row = $DB->get_record('local_forum_ai_queue', ['type' => 'post'], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->processed);
        $this->assertGreaterThanOrEqual($before + (5 * 60), (int) $row->timetoprocess);
        $this->assertLessThanOrEqual($after + (5 * 60), (int) $row->timetoprocess);
        $payload = json_decode($row->payload);
        $this->assertSame((int) $post->id, (int) $payload->postid);

        // No immediate adhoc dispatch in delayed mode.
        $this->assertSame(0, $DB->count_records('task_adhoc', ['component' => 'local_forum_ai']));
    }

    /**
     * MDL-INT-007: an internal observer failure must never block the student's
     * post; the observer swallows the error and reports it via debugging.
     */
    public function test_observer_failure_never_blocks_student_post(): void {
        $this->resetAfterTest();

        $fixture = $this->create_fixture();

        // A nonexistent post id makes the observer's own lookup fail internally.
        $event = $this->build_post_created_event($fixture, 9999999);

        $result = observer\post::post_created($event);

        $this->assertTrue($result);
        $this->assertDebuggingCalled();
    }

    /**
     * MDL-INT-008: the initial discussion post generates an AI reply only when
     * "enable AI reply to the discussion topic" is active.
     */
    public function test_discussion_topic_reply_only_when_option_enabled(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture([], [
            'require_approval' => 1,
            'enablediainitconversation' => 1,
        ]);

        $mock = $this->inject_mock(['reply' => 'Welcome to the discussion']);
        $messagesink = $this->redirectMessages();
        $this->run_discussion_task((int) $fixture->discussion->id, (int) $fixture->cm->id);
        $messagesink->close();

        $pending = $DB->get_record('local_forum_ai_pending', ['forumid' => $fixture->forum->id], '*', MUST_EXIST);
        $this->assertSame('pending', $pending->status);
        $this->assertSame((int) $fixture->discussion->firstpost, (int) $pending->parentpostid);
        $this->assertCount(1, $mock->requests);
    }

    /**
     * MDL-INT-008: with the topic option disabled the AI skips the opening post
     * but still replies to subsequent replies.
     */
    public function test_discussion_topic_skipped_when_option_disabled_but_replies_processed(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture([], [
            'require_approval' => 1,
            'enablediainitconversation' => 0,
        ]);

        $mock = $this->inject_mock(['reply' => 'Should not be requested']);
        $output = $this->run_discussion_task((int) $fixture->discussion->id, (int) $fixture->cm->id);

        $this->assertSame(0, $DB->count_records('local_forum_ai_pending'));
        $this->assertCount(0, $mock->requests);
        $this->assertStringContainsString('initial replies', $output);

        // A reply in the same forum is still processed normally.
        $post = $this->create_reply($fixture, $fixture->student->id, 'Follow-up reply');
        $messagesink = $this->redirectMessages();
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);
        $messagesink->close();

        $this->assertSame(1, $DB->count_records('local_forum_ai_pending', ['forumid' => $fixture->forum->id]));
        $this->assertCount(1, $mock->requests);
    }

    /**
     * MDL-INT-008 / MDL-INT-028: a "single" (simple discussion) forum detects
     * its auto-created discussion and processes it through the retry task.
     */
    public function test_single_forum_auto_discussion_detected_and_processed(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $forummodule = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'type' => 'single',
            'intro' => 'Single debate',
        ]);
        $cm = get_coursemodule_from_instance('forum', $forummodule->id, $course->id, false, MUST_EXIST);
        $forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        // forum_add_instance auto-created the single discussion.
        $discussion = $DB->get_record('forum_discussions', ['forum' => $forum->id], '*', MUST_EXIST);

        $this->set_forum_config($forum->id, [
            'require_approval' => 1,
            'enablediainitconversation' => 1,
        ]);

        // Creating the module already queued the observer's own retry task:
        // clear it so this test drives the execution explicitly.
        $DB->delete_records('task_adhoc');

        $singletask = new task\process_single_forum_discussion();
        $singletask->set_custom_data([
            'forumid' => $forum->id,
            'courseid' => $course->id,
            'contextid' => \context_module::instance($cm->id)->id,
            'retries' => 0,
        ]);
        $this->run_task_capturing_output($singletask);

        // The discussion was found and the AI discussion task was queued.
        $queued = $DB->get_record('task_adhoc', ['component' => 'local_forum_ai'], '*', MUST_EXIST);
        $this->assertSame('\\local_forum_ai\\task\\process_ai_discussion', $queued->classname);
        $customdata = json_decode($queued->customdata);
        $this->assertSame((int) $discussion->id, (int) $customdata->discussionid);

        // Executing the queued task produces the pending AI reply.
        $mock = $this->inject_mock(['reply' => 'Single forum opening reply']);
        $messagesink = $this->redirectMessages();
        $this->run_discussion_task((int) $discussion->id, (int) $cm->id);
        $messagesink->close();

        $this->assertSame(1, $DB->count_records('local_forum_ai_pending', ['forumid' => $forum->id]));
        $this->assertCount(1, $mock->requests);
    }

    /**
     * MDL-INT-008: when the single-forum discussion does not exist yet the task
     * requeues itself, and after exhausting the retries it gives up with a log.
     */
    public function test_single_forum_retries_and_gives_up_when_discussion_missing(): void {
        global $DB;

        $this->resetAfterTest();

        // A general forum with no discussions at all simulates the missing row.
        $course = $this->getDataGenerator()->create_course();
        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forummodule->id, $course->id, false, MUST_EXIST);
        $contextid = \context_module::instance($cm->id)->id;

        // First attempt: the task requeues itself with an incremented counter.
        $singletask = new task\process_single_forum_discussion();
        $singletask->set_custom_data([
            'forumid' => $forummodule->id,
            'courseid' => $course->id,
            'contextid' => $contextid,
            'retries' => 0,
        ]);
        $this->run_task_capturing_output($singletask);

        $requeued = $DB->get_record(
            'task_adhoc',
            ['classname' => '\\local_forum_ai\\task\\process_single_forum_discussion'],
            '*',
            MUST_EXIST
        );
        $customdata = json_decode($requeued->customdata);
        $this->assertSame(1, (int) $customdata->retries);

        $DB->delete_records('task_adhoc');

        // Final attempt: max retries reached, the task gives up and logs.
        $giveuptask = new task\process_single_forum_discussion();
        $giveuptask->set_custom_data([
            'forumid' => $forummodule->id,
            'courseid' => $course->id,
            'contextid' => $contextid,
            'retries' => task\process_single_forum_discussion::MAX_RETRIES,
        ]);
        $output = $this->run_task_capturing_output($giveuptask);

        $this->assertDebuggingCalled();
        $this->assertStringContainsString('Giving up', $output);
        $this->assertSame(0, $DB->count_records_select(
            'task_adhoc',
            'classname = ?',
            ['\\local_forum_ai\\task\\process_single_forum_discussion']
        ));
    }

    /**
     * MDL-INT-013: automatic mode without a configured grader degrades to the
     * manual approval flow — the response stays pending, nothing is published.
     */
    public function test_auto_mode_without_grader_degrades_to_pending(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture([], [
            'require_approval' => 0,
            'usedelay' => 0,
            'graderid' => null,
        ]);
        $post = $this->create_reply($fixture, $fixture->student->id, 'Reply without grader');
        $postcount = $DB->count_records('forum_posts');

        $this->inject_mock(['reply' => 'Degraded to pending']);
        $messagesink = $this->redirectMessages();
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);
        $messagesink->close();

        $debugging = $this->getDebuggingMessages();
        $this->resetDebugging();
        $found = array_filter($debugging, static function ($message): bool {
            return strpos($message->message, 'Automatic approval requires a configured grader') !== false;
        });
        $this->assertNotEmpty($found, 'The missing grader degradation must be logged.');

        $pending = $DB->get_record('local_forum_ai_pending', ['forumid' => $fixture->forum->id], '*', MUST_EXIST);
        $this->assertSame('pending', $pending->status);
        $this->assertEmpty($pending->postid);
        $this->assertSame($postcount, $DB->count_records('forum_posts'));
    }

    /**
     * MDL-INT-012: approving a response whose originating post was deleted
     * publishes the reply attached to the discussion's first post.
     */
    public function test_approve_with_deleted_origin_post_replies_to_first_post(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();

        $pending = new stdClass();
        $pending->discussionid = $fixture->discussion->id;
        $pending->forumid = $fixture->forum->id;
        $pending->parentpostid = 9999999; // The originating post no longer exists.
        $pending->creator_userid = $fixture->student->id;
        $pending->subject = 'Re: ' . $fixture->discussion->name;
        $pending->message = '<p>Reply to a deleted origin</p>';
        $pending->status = 'pending';
        $pending->approval_token = md5(uniqid('pipeline_', true));
        $pending->timecreated = time();
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);

        $this->setUser($fixture->teacher);

        $result = external\approve_response::execute($pending->approval_token, 'approve');
        $this->assertDebuggingCalled();
        $this->assertTrue($result['success']);

        $row = $DB->get_record('local_forum_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertNotEmpty($row->postid);
        $published = $DB->get_record('forum_posts', ['id' => $row->postid], '*', MUST_EXIST);
        $this->assertSame((int) $fixture->discussion->firstpost, (int) $published->parent);
    }

    /**
     * MDL-INT-017: an empty AI reply should be discarded or flagged instead of
     * silently creating a pending row / publishing an empty post.
     */
    public function test_empty_reply_text_is_discarded_or_warned(): void {
        $this->markTestSkipped(
            'Una respuesta con texto vacio igualmente crea la pendiente o publica una publicacion vacia ' .
            'sin avisar al profesor — el descarte o aviso esta pendiente de implementar.'
        );
    }

    /**
     * MDL-INT-017 / MDL-INT-030: a service failure is logged for diagnosis and
     * rethrown so the adhoc framework retries the task (current documented behavior).
     */
    public function test_service_failure_is_logged_and_rethrown_for_retry(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $post = $this->create_reply($fixture, $fixture->student->id, 'Reply hitting a broken service');

        $mock = $this->inject_mock();
        $failure = new \RuntimeException('AI service unavailable');
        $mock->fail_with($failure);

        $thrown = null;
        try {
            $this->run_post_task((int) $post->id, (int) $fixture->cm->id);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertSame($failure, $thrown, 'The failure must be rethrown so the framework retries.');

        $debugging = $this->getDebuggingMessages();
        $this->resetDebugging();
        $found = array_filter($debugging, static function ($message): bool {
            return strpos($message->message, 'Error in process_ai_post task') !== false;
        });
        $this->assertNotEmpty($found, 'The service failure must be logged for diagnosis.');

        // Nothing was stored or published for the failed call.
        $this->assertSame(0, $DB->count_records('local_forum_ai_pending'));
    }

    /**
     * A named-scale label returned by the service is resolved to its scale index.
     */
    public function test_named_scale_label_is_normalized_before_rating(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Mal, Regular, Bien']);
        $fixture = $this->create_fixture(['assessed' => 1, 'scale' => -$scale->id]);
        $this->set_forum_config((int) $fixture->forum->id, [
            'require_approval' => 0,
            'graderid' => $fixture->teacher->id,
        ]);
        $post = $this->create_reply($fixture, $fixture->student->id, 'Reply graded with a scale label');

        $this->inject_mock(['reply' => 'Mock AI reply', 'grade' => 'Bien']);
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);

        $pending = $DB->get_record('local_forum_ai_pending', ['parentpostid' => $post->id], '*', MUST_EXIST);
        $this->assertSame(3, (int) $pending->grade);

        $rating = $DB->get_record('rating', ['itemid' => $post->id], '*', MUST_EXIST);
        $this->assertSame(3.0, (float) $rating->rating);
    }

    /**
     * A grade that cannot be resolved must leave the student without any rating.
     */
    public function test_unresolvable_grade_applies_no_rating(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Mal, Regular, Bien']);
        $fixture = $this->create_fixture(['assessed' => 1, 'scale' => -$scale->id]);
        $this->set_forum_config((int) $fixture->forum->id, [
            'require_approval' => 0,
            'graderid' => $fixture->teacher->id,
        ]);
        $post = $this->create_reply($fixture, $fixture->student->id, 'Reply graded with an unknown label');

        $this->inject_mock(['reply' => 'Mock AI reply', 'grade' => 'Excelente']);
        $output = $this->run_post_task((int) $post->id, (int) $fixture->cm->id);

        $pending = $DB->get_record('local_forum_ai_pending', ['parentpostid' => $post->id], '*', MUST_EXIST);
        $this->assertNull($pending->grade);
        $this->assertSame(0, $DB->count_records('rating', ['itemid' => $post->id]));
        $this->assertStringContainsString('no usable grade', $output);
    }

    /**
     * An explicit and legitimate zero is still applied as a real rating.
     */
    public function test_explicit_zero_grade_is_applied(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture(['assessed' => 1, 'scale' => 100]);
        $this->set_forum_config((int) $fixture->forum->id, [
            'require_approval' => 0,
            'graderid' => $fixture->teacher->id,
        ]);
        $post = $this->create_reply($fixture, $fixture->student->id, 'Reply graded with a legitimate zero');

        $this->inject_mock(['reply' => 'Mock AI reply', 'grade' => 0]);
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);

        $pending = $DB->get_record('local_forum_ai_pending', ['parentpostid' => $post->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $pending->grade);

        $rating = $DB->get_record('rating', ['itemid' => $post->id], '*', MUST_EXIST);
        $this->assertSame(0.0, (float) $rating->rating);
    }

    /**
     * MDL-INT-030: the plugin should bound its own retries (limit or timeout)
     * instead of re-calling the paid service on every framework retry.
     */
    public function test_service_retries_are_bounded_by_plugin_limit(): void {
        $this->markTestSkipped(
            'La tarea reintenta la llamada completa sin limite propio ni timeout, consumiendo creditos ' .
            'en cada intento — el control de costos esta pendiente de implementar.'
        );
    }

    /**
     * MDL-INT-009: only due queue rows are dispatched, in due-time order.
     */
    public function test_queue_processes_only_due_rows_in_due_order(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $earlypost = $this->create_reply($fixture, $fixture->student->id, 'Earlier due reply');
        $latepost = $this->create_reply($fixture, $fixture->student->id, 'Later due reply');

        $now = time();
        // Inserted out of order on purpose: dispatch must follow timetoprocess.
        $laterowid = $this->create_queue_row((int) $latepost->id, (int) $fixture->cm->id, $now - 50);
        $earlyrowid = $this->create_queue_row((int) $earlypost->id, (int) $fixture->cm->id, $now - 500);
        $futurerowid = $this->create_queue_row((int) $earlypost->id, (int) $fixture->cm->id, $now + 3600);

        $queue = new task\process_ai_queue();
        $this->run_task_capturing_output($queue);

        // Only the two due rows were dispatched and removed.
        $this->assertFalse($DB->record_exists('local_forum_ai_queue', ['id' => $earlyrowid]));
        $this->assertFalse($DB->record_exists('local_forum_ai_queue', ['id' => $laterowid]));
        $this->assertTrue($DB->record_exists('local_forum_ai_queue', ['id' => $futurerowid]));

        $tasks = array_values($DB->get_records('task_adhoc', ['component' => 'local_forum_ai'], 'id ASC'));
        $this->assertCount(2, $tasks);
        $this->assertSame((int) $earlypost->id, (int) json_decode($tasks[0]->customdata)->postid);
        $this->assertSame((int) $latepost->id, (int) json_decode($tasks[1]->customdata)->postid);
    }

    /**
     * MDL-INT-009: at most 20 queue entries are dispatched per execution.
     */
    public function test_queue_batch_limit_is_twenty_per_run(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $post = $this->create_reply($fixture, $fixture->student->id, 'Batch reply');

        for ($i = 0; $i < 25; $i++) {
            $this->create_queue_row((int) $post->id, (int) $fixture->cm->id, time() - 100 + $i);
        }

        $queue = new task\process_ai_queue();
        $this->run_task_capturing_output($queue);

        $this->assertSame(20, $DB->count_records('task_adhoc', ['component' => 'local_forum_ai']));
        $this->assertSame(5, $DB->count_records('local_forum_ai_queue'));

        // The next run drains the remainder.
        $this->run_task_capturing_output($queue);
        $this->assertSame(25, $DB->count_records('task_adhoc', ['component' => 'local_forum_ai']));
        $this->assertSame(0, $DB->count_records('local_forum_ai_queue'));
    }

    /**
     * MDL-INT-009: the scheduled task stops completely when the plugin or the
     * global AI flag is disabled, leaving the queue untouched.
     */
    public function test_queue_aborts_when_globally_disabled(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $post = $this->create_reply($fixture, $fixture->student->id, 'Reply while disabled');
        $rowid = $this->create_queue_row((int) $post->id, (int) $fixture->cm->id, time() - 100);

        $queue = new task\process_ai_queue();

        set_config('enableforumai', 0, 'local_forum_ai');
        $this->run_task_capturing_output($queue);
        $this->assertTrue($DB->record_exists('local_forum_ai_queue', ['id' => $rowid]));
        $this->assertSame(0, $DB->count_records('task_adhoc', ['component' => 'local_forum_ai']));

        set_config('enableforumai', 1, 'local_forum_ai');
        set_config('default_enabled', 0, 'local_forum_ai');
        $this->run_task_capturing_output($queue);
        $this->assertTrue($DB->record_exists('local_forum_ai_queue', ['id' => $rowid]));
        $this->assertSame(0, $DB->count_records('task_adhoc', ['component' => 'local_forum_ai']));
    }

    /**
     * MDL-INT-009: consecutive posts of the same student within the waiting
     * window should be deduplicated into a single AI response.
     */
    public function test_queue_deduplicates_same_student_posts(): void {
        $this->markTestSkipped(
            'No hay deduplicacion — cada publicacion consecutiva del mismo estudiante genera una entrada ' .
            'y una respuesta de IA independiente; mejora pendiente.'
        );
    }

    /**
     * MDL-INT-028: replies are processed normally in general, each-person-one-topic
     * and blog format forums (step 3, Q&A visibility, is Behat territory).
     */
    public function test_replies_processed_in_general_eachuser_and_blog_forums(): void {
        global $DB;

        $this->resetAfterTest();

        foreach (['general', 'eachuser', 'blog'] as $type) {
            $fixture = $this->create_fixture(['type' => $type]);
            $post = $this->create_reply($fixture, $fixture->student->id, "Reply in {$type} forum");

            $mock = $this->inject_mock(['reply' => "AI reply for {$type}"]);
            $messagesink = $this->redirectMessages();
            $this->run_post_task((int) $post->id, (int) $fixture->cm->id);
            $messagesink->close();

            $this->assertSame(
                1,
                $DB->count_records('local_forum_ai_pending', ['forumid' => $fixture->forum->id]),
                "A pending AI reply is expected for forum type {$type}."
            );
            $this->assertCount(1, $mock->requests, "Exactly one AI request is expected for forum type {$type}.");
        }
    }

    /**
     * Creates course, users, forum, discussion and an enabled config row.
     *
     * @param array $forumoptions Extra forum options (e.g. type).
     * @param array $configoverrides Extra plugin config fields.
     * @return stdClass Fixture holder (course, student, teacher, forum, cm, discussion).
     */
    private function create_fixture(array $forumoptions = [], array $configoverrides = []): stdClass {
        global $DB;

        $fixture = new stdClass();
        $fixture->course = $this->getDataGenerator()->create_course();
        $fixture->student = $this->getDataGenerator()->create_and_enrol($fixture->course, 'student');
        $fixture->teacher = $this->getDataGenerator()->create_and_enrol($fixture->course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module(
            'forum',
            array_merge(['course' => $fixture->course->id], $forumoptions)
        );
        $fixture->cm = get_coursemodule_from_instance('forum', $forummodule->id, $fixture->course->id, false, MUST_EXIST);
        $fixture->forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $fixture->course->id,
            'forum' => $fixture->forum->id,
            'userid' => $fixture->student->id,
        ]);
        $fixture->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $this->set_forum_config((int) $fixture->forum->id, $configoverrides);

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
     * Builds a post_created event for the fixture forum.
     *
     * @param stdClass $fixture Fixture holder.
     * @param int $postid Post id carried by the event.
     * @return \mod_forum\event\post_created
     */
    private function build_post_created_event(stdClass $fixture, int $postid): \mod_forum\event\post_created {
        return \mod_forum\event\post_created::create([
            'context' => \context_module::instance($fixture->cm->id),
            'objectid' => $postid,
            'other' => [
                'discussionid' => $fixture->discussion->id,
                'forumid' => $fixture->forum->id,
                'forumtype' => $fixture->forum->type,
            ],
        ]);
    }

    /**
     * Runs the process_ai_post adhoc task for a post.
     *
     * @param int $postid Post id.
     * @param int $cmid Course module id.
     * @return string Captured mtrace output.
     */
    private function run_post_task(int $postid, int $cmid): string {
        $task = new task\process_ai_post();
        $task->set_custom_data((object) ['postid' => $postid, 'cmid' => $cmid]);

        return $this->run_task_capturing_output($task);
    }

    /**
     * Runs the process_ai_discussion adhoc task for a discussion.
     *
     * @param int $discussionid Discussion id.
     * @param int $cmid Course module id.
     * @return string Captured mtrace output.
     */
    private function run_discussion_task(int $discussionid, int $cmid): string {
        $task = new task\process_ai_discussion();
        $task->set_custom_data((object) ['discussionid' => $discussionid, 'cmid' => $cmid]);

        return $this->run_task_capturing_output($task);
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
     * Inserts a delayed queue row for a post.
     *
     * @param int $postid Post id.
     * @param int $cmid Course module id.
     * @param int $timetoprocess Due timestamp.
     * @return int Queue row id.
     */
    private function create_queue_row(int $postid, int $cmid, int $timetoprocess): int {
        global $DB;

        return (int) $DB->insert_record('local_forum_ai_queue', (object) [
            'type' => 'post',
            'payload' => json_encode((object) ['postid' => $postid, 'cmid' => $cmid]),
            'timecreated' => time(),
            'timetoprocess' => $timetoprocess,
            'processed' => 0,
        ]);
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
