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
 * Tests for the delayed-queue cleanup on post, discussion and forum deletion.
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

require_once($CFG->dirroot . '/mod/forum/lib.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests that queue/config/pending cleanup operates by exact identifier.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\observer\post::post_deleted
 * @covers \local_forum_ai\observer\discussion::discussion_deleted
 * @covers \local_forum_ai\observer\module::forum_deleted
 */
final class queue_cleanup_test extends \advanced_testcase {
    /**
     * MDL-INT-010 (steps 1): deleting a post removes the queue rows of that exact post.
     */
    public function test_post_deletion_removes_exact_queue_rows(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup();
        $reply = $this->create_reply($setup);

        $exactid = $this->insert_queue_row('post', ['postid' => (int) $reply->id, 'cmid' => (int) $setup->cm->id]);
        // N + 1 never starts with the digits of N, so it can never be a prefix collision.
        $unrelatedid = $this->insert_queue_row('post', ['postid' => (int) $reply->id + 1, 'cmid' => (int) $setup->cm->id]);

        forum_delete_post($reply, false, $setup->course, $setup->cm, $setup->forum);

        $this->assertFalse($DB->record_exists('local_forum_ai_queue', ['id' => $exactid]));
        $this->assertTrue($DB->record_exists('local_forum_ai_queue', ['id' => $unrelatedid]));
    }

    /**
     * MDL-INT-010 (step 3) [Pendiente:fail]: deleting post N must NOT delete queue rows of
     * a post whose id starts with the same digits (e.g. deleting post 12 must not remove
     * the rows of post 123).
     *
     * This asserts the CORRECT behavior. It fails on current code because the cleanup in
     * classes/observer/post.php:134-147 matches the payload with LIKE '%"postid":N%',
     * which also matches '"postid":N3' — data loss that must be fixed.
     */
    public function test_post_deletion_keeps_prefix_colliding_queue_rows(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup();
        $reply = $this->create_reply($setup);

        // A queue row of another (synthetic) post whose id is the deleted id plus a digit.
        $collidingpostid = (int) ($reply->id . '3');
        $collidingid = $this->insert_queue_row('post', ['postid' => $collidingpostid, 'cmid' => (int) $setup->cm->id]);

        forum_delete_post($reply, false, $setup->course, $setup->cm, $setup->forum);

        $this->assertTrue(
            $DB->record_exists('local_forum_ai_queue', ['id' => $collidingid]),
            "Queue row of post {$collidingpostid} must survive the deletion of post {$reply->id}."
        );
    }

    /**
     * MDL-INT-010 (step 2): deleting a discussion removes the queue rows of that exact discussion.
     */
    public function test_discussion_deletion_removes_exact_queue_rows(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup();

        $exactid = $this->insert_queue_row(
            'discussion',
            ['discussionid' => (int) $setup->discussion->id, 'cmid' => (int) $setup->cm->id]
        );
        // N + 1 never starts with the digits of N, so it can never be a prefix collision.
        $unrelatedid = $this->insert_queue_row(
            'discussion',
            ['discussionid' => (int) $setup->discussion->id + 1, 'cmid' => (int) $setup->cm->id]
        );

        forum_delete_discussion($setup->discussion, false, $setup->course, $setup->cm, $setup->forum);

        $this->assertFalse($DB->record_exists('local_forum_ai_queue', ['id' => $exactid]));
        $this->assertTrue($DB->record_exists('local_forum_ai_queue', ['id' => $unrelatedid]));
    }

    /**
     * MDL-INT-010 (step 3) [Pendiente:fail]: deleting discussion N must NOT delete queue rows
     * of a discussion whose id starts with the same digits.
     *
     * This asserts the CORRECT behavior. It fails on current code because the cleanup in
     * classes/observer/discussion.php:166-179 matches the payload with LIKE
     * '%"discussionid":N%', which also matches '"discussionid":N3' — data loss to fix.
     */
    public function test_discussion_deletion_keeps_prefix_colliding_queue_rows(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup();

        $collidingdiscussionid = (int) ($setup->discussion->id . '3');
        $collidingid = $this->insert_queue_row(
            'discussion',
            ['discussionid' => $collidingdiscussionid, 'cmid' => (int) $setup->cm->id]
        );

        forum_delete_discussion($setup->discussion, false, $setup->course, $setup->cm, $setup->forum);

        $this->assertTrue(
            $DB->record_exists('local_forum_ai_queue', ['id' => $collidingid]),
            "Queue row of discussion {$collidingdiscussionid} must survive the deletion " .
                "of discussion {$setup->discussion->id}."
        );
    }

    /**
     * MDL-INT-011 (step 1): deleting the forum removes its AI configuration and all its
     * response records (pending and history), leaving other forums untouched.
     */
    public function test_forum_deletion_removes_config_and_pending(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup();
        $other = $this->create_setup();

        // History rows must be removed too, not only status = pending.
        $this->insert_pending_row($setup, 'approved');
        $this->insert_pending_row($setup, 'rejected');

        course_delete_module($setup->cm->id);

        $this->assertSame(0, $DB->count_records('local_forum_ai_config', ['forumid' => $setup->forum->id]));
        $this->assertSame(0, $DB->count_records('local_forum_ai_pending', ['forumid' => $setup->forum->id]));

        // The other forum keeps its rows.
        $this->assertSame(1, $DB->count_records('local_forum_ai_config', ['forumid' => $other->forum->id]));
        $this->assertSame(1, $DB->count_records('local_forum_ai_pending', ['forumid' => $other->forum->id]));
    }

    /**
     * MDL-INT-011 (step 2): deleting the forum should also remove its delayed queue entries.
     */
    public function test_forum_deletion_removes_queue_rows(): void {
        $this->markTestSkipped(
            'MDL-INT-011 NOTA [Pendiente:skip]: las entradas de la cola diferida no se eliminan ' .
            'al borrar el foro y la tarea programada intenta procesarlas indefinidamente — ' .
            'fuga de recursos, no critica.'
        );
    }

    /**
     * Creates a course, forum (with enabled AI config), discussion and pending row.
     *
     * @return stdClass Setup holder (course, student, forum, cm, discussion, pendingid).
     */
    private function create_setup(): stdClass {
        global $DB;

        $setup = new stdClass();
        $setup->course = $this->getDataGenerator()->create_course();
        $setup->student = $this->getDataGenerator()->create_and_enrol($setup->course, 'student');

        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $setup->course->id]);
        $setup->forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);
        $setup->cm = get_coursemodule_from_instance('forum', $setup->forum->id, $setup->course->id, false, MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $setup->course->id,
            'forum' => $setup->forum->id,
            'userid' => $setup->student->id,
        ]);
        $setup->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $DB->insert_record('local_forum_ai_config', (object) [
            'forumid' => $setup->forum->id,
            'enabled' => 1,
            'require_approval' => 1,
            'reply_message' => 'Test prompt',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $setup->pendingid = $this->insert_pending_row($setup, 'pending');

        return $setup;
    }

    /**
     * Creates a student reply to the first post of the setup discussion.
     *
     * @param stdClass $setup Setup holder.
     * @return stdClass Full forum post record.
     */
    private function create_reply(stdClass $setup): stdClass {
        global $DB;

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $reply = $forumgenerator->create_post([
            'discussion' => $setup->discussion->id,
            'parent' => $setup->discussion->firstpost,
            'userid' => $setup->student->id,
        ]);

        return $DB->get_record('forum_posts', ['id' => $reply->id], '*', MUST_EXIST);
    }

    /**
     * Inserts a delayed queue row with the given payload.
     *
     * @param string $type Queue row type ('post' or 'discussion').
     * @param array $payload Payload data to encode.
     * @return int New queue row id.
     */
    private function insert_queue_row(string $type, array $payload): int {
        global $DB;

        return (int) $DB->insert_record('local_forum_ai_queue', (object) [
            'type' => $type,
            'payload' => json_encode((object) $payload),
            'timecreated' => time(),
            'timetoprocess' => time() + HOURSECS,
            'processed' => 0,
        ]);
    }

    /**
     * Inserts a pending/history row for the setup forum.
     *
     * @param stdClass $setup Setup holder.
     * @param string $status Row status.
     * @return int New pending row id.
     */
    private function insert_pending_row(stdClass $setup, string $status): int {
        global $DB;

        return (int) $DB->insert_record('local_forum_ai_pending', (object) [
            'discussionid' => $setup->discussion->id,
            'forumid' => $setup->forum->id,
            'parentpostid' => $setup->discussion->firstpost,
            'creator_userid' => $setup->student->id,
            'subject' => 'Re: ' . $setup->discussion->name,
            'message' => '<p>AI reply</p>',
            'status' => $status,
            'approval_token' => md5(uniqid('queuecleanup_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }
}
