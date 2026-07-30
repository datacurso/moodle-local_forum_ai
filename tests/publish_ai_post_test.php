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
 * Tests for the shared AI post publisher of local_forum_ai.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

use stdClass;

/**
 * Tests that AI posts are published through the standard forum flow.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\approval
 */
final class publish_ai_post_test extends \advanced_testcase {
    /**
     * Auto publication attributes the post to the grader and follows the standard flow.
     */
    public function test_auto_publish_uses_standard_flow(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        // The delayed queue path runs as admin: the publisher must switch to the author.
        $this->setAdminUser();
        $adminid = (int) $USER->id;

        $fixture = $this->create_fixture();

        $pending = $this->create_approved_pending($fixture);

        $sink = $this->redirectEvents();
        $postid = approval::publish_ai_post(
            $fixture->discussion,
            $fixture->forum,
            $fixture->cm,
            $fixture->course,
            $pending,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->grader->id
        );
        $events = $sink->get_events();
        $sink->close();

        $this->assertIsInt($postid);

        // The post is attributed to the grader, not to admin nor the student.
        $post = $DB->get_record('forum_posts', ['id' => $postid], '*', MUST_EXIST);
        $this->assertEquals($fixture->grader->id, $post->userid);
        $this->assertEquals(0, $post->messagetrust);

        // The pending row is linked to the published post.
        $this->assertEquals(
            $postid,
            $DB->get_field('local_forum_ai_pending', 'postid', ['id' => $pending->id], MUST_EXIST)
        );

        // The post_created event fired with the right objectid.
        $created = array_filter($events, function ($event) {
            return $event instanceof \mod_forum\event\post_created;
        });
        $this->assertCount(1, $created);
        $this->assertEquals($postid, reset($created)->objectid);

        // The original user is restored after the internal switch.
        $this->assertEquals($adminid, (int) $USER->id);
    }

    /**
     * Publication updates the activity completion state for the author.
     */
    public function test_completion_updated_for_grader(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1);

        $fixture = $this->create_fixture(
            ['enablecompletion' => 1],
            ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionreplies' => 1]
        );

        $pending = $this->create_approved_pending($fixture);

        $sink = $this->redirectEvents();
        $postid = approval::publish_ai_post(
            $fixture->discussion,
            $fixture->forum,
            $fixture->cm,
            $fixture->course,
            $pending,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->grader->id
        );
        $sink->close();

        $this->assertIsInt($postid);

        $completion = new \completion_info($fixture->course);
        $data = $completion->get_data($fixture->cm, false, $fixture->grader->id);
        $this->assertEquals(COMPLETION_COMPLETE, $data->completionstate);
    }

    /**
     * A published AI post must never re-trigger generation through the observer.
     */
    public function test_auto_publish_does_not_retrigger_generation(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture();

        $pending = $this->create_approved_pending($fixture);

        // No event sink: post_created must reach the plugin's own observer.
        $postid = approval::publish_ai_post(
            $fixture->discussion,
            $fixture->forum,
            $fixture->cm,
            $fixture->course,
            $pending,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->grader->id
        );

        $this->assertIsInt($postid);
        $this->assertSame(0, $DB->count_records('task_adhoc', ['component' => 'local_forum_ai']));
        $this->assertSame(0, $DB->count_records('local_forum_ai_queue'));
    }

    /**
     * Manual approval fires the event and stores the published post id.
     *
     * @covers \local_forum_ai\external\approve_response
     */
    public function test_manual_approve_fires_event_and_stores_postid(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $pending = $this->create_pending_row($fixture);

        $this->setUser($fixture->grader);

        $sink = $this->redirectEvents();
        $result = external\approve_response::execute($pending->approval_token, 'approve');
        $events = $sink->get_events();
        $sink->close();

        $this->assertTrue($result['success']);

        $row = $DB->get_record('local_forum_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertSame('approved', $row->status);
        $this->assertNotEmpty($row->postid);

        $created = array_filter($events, function ($event) {
            return $event instanceof \mod_forum\event\post_created;
        });
        $this->assertCount(1, $created);
        $this->assertEquals($row->postid, reset($created)->objectid);
    }

    /**
     * Manual approval must not re-trigger generation through the observer.
     *
     * @covers \local_forum_ai\external\approve_response
     */
    public function test_manual_approve_does_not_retrigger_generation(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $pending = $this->create_pending_row($fixture);

        $this->setUser($fixture->grader);

        // No event sink: post_created must reach the plugin's own observer.
        $result = external\approve_response::execute($pending->approval_token, 'approve');
        $this->assertTrue($result['success']);

        $this->assertSame(0, $DB->count_records('task_adhoc', ['component' => 'local_forum_ai']));
        $this->assertSame(0, $DB->count_records('local_forum_ai_queue'));
        // No new pending rows were generated after the approval.
        $this->assertSame(1, $DB->count_records('local_forum_ai_pending'));
    }

    /**
     * Auto and manual publications produce structurally equivalent posts.
     *
     * @covers \local_forum_ai\external\approve_response
     */
    public function test_auto_and_manual_posts_structurally_equivalent(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture();

        // Auto path.
        $autopending = $this->create_approved_pending($fixture);
        $autopostid = approval::publish_ai_post(
            $fixture->discussion,
            $fixture->forum,
            $fixture->cm,
            $fixture->course,
            $autopending,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->grader->id
        );

        // Manual path.
        $manualpending = $this->create_pending_row($fixture);
        $this->setUser($fixture->grader);
        external\approve_response::execute($manualpending->approval_token, 'approve');
        $manualpostid = $DB->get_field(
            'local_forum_ai_pending',
            'postid',
            ['id' => $manualpending->id],
            MUST_EXIST
        );

        $autopost = $DB->get_record('forum_posts', ['id' => $autopostid], '*', MUST_EXIST);
        $manualpost = $DB->get_record('forum_posts', ['id' => $manualpostid], '*', MUST_EXIST);

        $structural = ['messageformat', 'messagetrust', 'mailed', 'mailnow', 'attachment', 'deleted', 'privatereplyto'];
        foreach ($structural as $field) {
            $this->assertEquals($autopost->{$field}, $manualpost->{$field}, "Field {$field} differs between modes.");
        }
    }

    /**
     * A missing, deleted or suspended author yields a clean false, never a throw.
     */
    public function test_publish_returns_false_for_missing_or_inactive_author(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture();
        $postcount = $DB->count_records('forum_posts');

        // Nonexistent author.
        $pending = $this->create_approved_pending($fixture);
        $result = approval::publish_ai_post(
            $fixture->discussion,
            $fixture->forum,
            $fixture->cm,
            $fixture->course,
            $pending,
            (int) $fixture->discussion->firstpost,
            999999
        );
        $this->assertDebuggingCalled();
        $this->assertFalse($result);

        // Suspended author.
        $DB->set_field('user', 'suspended', 1, ['id' => $fixture->grader->id]);
        $pending = $this->create_approved_pending($fixture);
        $result = approval::publish_ai_post(
            $fixture->discussion,
            $fixture->forum,
            $fixture->cm,
            $fixture->course,
            $pending,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->grader->id
        );
        $this->assertDebuggingCalled();
        $this->assertFalse($result);

        // Deleted author.
        $DB->set_field('user', 'suspended', 0, ['id' => $fixture->grader->id]);
        $DB->set_field('user', 'deleted', 1, ['id' => $fixture->grader->id]);
        $pending = $this->create_approved_pending($fixture);
        $result = approval::publish_ai_post(
            $fixture->discussion,
            $fixture->forum,
            $fixture->cm,
            $fixture->course,
            $pending,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->grader->id
        );
        $this->assertDebuggingCalled();
        $this->assertFalse($result);

        $this->assertSame($postcount, $DB->count_records('forum_posts'));
    }

    /**
     * Once the post exists, follow-up failures must not abort the publication.
     *
     * A rethrow after the insert would make adhoc retries (or a double approve)
     * create duplicate posts; the publisher must log and still return the id.
     */
    public function test_publish_survives_followup_failures(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture();
        $pending = $this->create_approved_pending($fixture);

        // A broken cm makes the post-insert follow-ups (event context) fail.
        $badcm = clone $fixture->cm;
        $badcm->id = 999999;

        $postid = approval::publish_ai_post(
            $fixture->discussion,
            $fixture->forum,
            $badcm,
            $fixture->course,
            $pending,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->grader->id
        );
        $this->assertDebuggingCalled();

        $this->assertIsInt($postid);
        $this->assertTrue($DB->record_exists('forum_posts', ['id' => $postid]));
        // The pending link is written before the failing follow-up.
        $this->assertEquals(
            $postid,
            $DB->get_field('local_forum_ai_pending', 'postid', ['id' => $pending->id], MUST_EXIST)
        );
    }

    /**
     * A pending row already linked to a published post must never be re-published.
     *
     * @covers \local_forum_ai\external\approve_response
     */
    public function test_approve_rejects_already_published_row(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $pending = $this->create_pending_row($fixture);

        // Simulate a restored/legacy row: still pending but already holding a post id.
        $DB->set_field('local_forum_ai_pending', 'postid', $fixture->discussion->firstpost, ['id' => $pending->id]);

        $this->setUser($fixture->grader);
        $postcount = $DB->count_records('forum_posts');

        try {
            external\approve_response::execute($pending->approval_token, 'approve');
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_responsenotpending', $e->errorcode);
        }

        $this->assertSame($postcount, $DB->count_records('forum_posts'));
    }

    /**
     * A private parent yields a clean false, never a coding_exception.
     */
    public function test_publish_returns_false_for_private_parent(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture();

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $privateparent = $forumgenerator->create_post([
            'discussion' => $fixture->discussion->id,
            'parent' => $fixture->discussion->firstpost,
            'userid' => $fixture->grader->id,
            'privatereplyto' => $fixture->student->id,
        ]);

        $pending = $this->create_approved_pending($fixture);
        $postcount = $DB->count_records('forum_posts');

        $result = approval::publish_ai_post(
            $fixture->discussion,
            $fixture->forum,
            $fixture->cm,
            $fixture->course,
            $pending,
            (int) $privateparent->id,
            (int) $fixture->grader->id
        );
        $this->assertDebuggingCalled();

        $this->assertFalse($result);
        $this->assertSame($postcount, $DB->count_records('forum_posts'));
        $this->assertEmpty($DB->get_field('local_forum_ai_pending', 'postid', ['id' => $pending->id], MUST_EXIST));
    }

    /**
     * Creates the shared course/forum/discussion fixture with an enabled config row.
     *
     * @param array $courseoptions Extra course options.
     * @param array $forumoptions Extra forum options.
     * @return stdClass Fixture holder (course, student, grader, forum, cm, discussion).
     */
    private function create_fixture(array $courseoptions = [], array $forumoptions = []): stdClass {
        global $DB;

        $fixture = new stdClass();
        $fixture->course = $this->getDataGenerator()->create_course($courseoptions);
        $fixture->student = $this->getDataGenerator()->create_and_enrol($fixture->course, 'student');
        $fixture->grader = $this->getDataGenerator()->create_and_enrol($fixture->course, 'editingteacher');

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

        // AI enabled with a configured grader (immediate mode would queue adhoc tasks).
        $configrow = $DB->get_record('local_forum_ai_config', ['forumid' => $fixture->forum->id]) ?: new stdClass();
        $configrow->forumid = $fixture->forum->id;
        $configrow->enabled = 1;
        $configrow->require_approval = 1;
        $configrow->graderid = $fixture->grader->id;
        $configrow->reply_message = 'Test prompt';
        $configrow->timemodified = time();

        if (empty($configrow->id)) {
            $configrow->timecreated = time();
            $DB->insert_record('local_forum_ai_config', $configrow);
        } else {
            $DB->update_record('local_forum_ai_config', $configrow);
        }

        return $fixture;
    }

    /**
     * Creates an approved pending row through create_approval_request.
     *
     * @param stdClass $fixture Fixture holder.
     * @return stdClass The pending row.
     */
    private function create_approved_pending(stdClass $fixture): stdClass {
        global $DB;

        $pendingid = approval::create_approval_request(
            $fixture->discussion,
            $fixture->forum,
            '<p>AI reply ready to publish</p>',
            'approved',
            (int) $fixture->discussion->firstpost,
            null,
            (int) $fixture->grader->id
        );
        $this->assertNotEmpty($pendingid);

        return $DB->get_record('local_forum_ai_pending', ['id' => $pendingid], '*', MUST_EXIST);
    }

    /**
     * Inserts a pending row awaiting manual approval.
     *
     * @param stdClass $fixture Fixture holder.
     * @return stdClass The pending row.
     */
    private function create_pending_row(stdClass $fixture): stdClass {
        global $DB;

        $pending = new stdClass();
        $pending->discussionid = $fixture->discussion->id;
        $pending->forumid = $fixture->forum->id;
        $pending->parentpostid = $fixture->discussion->firstpost;
        $pending->creator_userid = $fixture->student->id;
        $pending->subject = 'Re: ' . $fixture->discussion->name;
        $pending->message = '<p>AI reply awaiting approval</p>';
        $pending->status = 'pending';
        $pending->approval_token = md5(uniqid('publish_', true));
        $pending->timecreated = time();
        $pending->timemodified = time();
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);

        return $pending;
    }
}
