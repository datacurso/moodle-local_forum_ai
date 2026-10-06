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
 * Tests for the exclusive, conditional state transitions of pending AI responses.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai\external;

defined('MOODLE_INTERNAL') || die();

use externallib_advanced_testcase;
use local_forum_ai\approval;
use moodle_exception;
use stdClass;

global $CFG;

require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests that approve, reject and edit cannot overwrite each other.
 *
 * Real concurrency cannot run inside one PHPUnit process, so each interleaving
 * is reproduced deterministically: a held lock, a row changed by a writer that
 * ignores the lock, or sequential calls over an already managed row.
 *
 * Covers: FAI-SEC-010 — approval/edit race condition.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\external\approve_response
 * @covers \local_forum_ai\external\update_response
 * @covers \local_forum_ai\external\get_details
 * @covers \local_forum_ai\approval
 */
final class approve_response_concurrency_test extends externallib_advanced_testcase {
    /**
     * An approval must give up while another request holds the lock of the row.
     *
     * The database record lock factory is forced because the MySQL and PostgreSQL
     * factories rely on session-level advisory locks that the same connection can
     * acquire again, so they cannot simulate a second request in one process.
     */
    public function test_approve_fails_while_lock_is_held(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';

        [$pending, , $teacher] = $this->create_pending_response();
        $this->setUser($teacher);
        $postcount = $DB->count_records('forum_posts');

        $lock = \core\lock\lock_config::get_lock_factory('local_forum_ai')->get_lock('pending_' . $pending->id, 0);
        $this->assertNotFalse($lock);

        try {
            approve_response::execute($pending->approval_token, 'approve');
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_responsebusy', $e->errorcode);
        } finally {
            $lock->release();
        }

        $this->assertSame($postcount, $DB->count_records('forum_posts'));
        $row = $DB->get_record('local_forum_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->postid);
    }

    /**
     * An edit that started before an approval must never revert the approved row.
     */
    public function test_stale_edit_cannot_revert_approved_row(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, , $teacher] = $this->create_pending_response();
        $this->setUser($teacher);

        approve_response::execute($pending->approval_token, 'approve');
        $approved = $DB->get_record('local_forum_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertSame('approved', $approved->status);
        $this->assertNotEmpty($approved->postid);

        // The edit endpoint refuses the managed row.
        try {
            update_response::execute($pending->approval_token, 'Stale edit');
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_responsenotpending', $e->errorcode);
        }
        $this->assertEquals($approved, $DB->get_record('local_forum_ai_pending', ['id' => $pending->id]));

        // Defence in depth: a writer that ignores the lock approves the row while
        // the edit is composing its message; the conditional write must not apply.
        [$other] = $this->create_pending_response();
        $postid = (int) $approved->postid;
        try {
            approval::edit_pending_message((int) $other->id, static function () use ($DB, $other, $postid): string {
                $DB->update_record('local_forum_ai_pending', (object) [
                    'id' => $other->id,
                    'status' => 'approved',
                    'postid' => $postid,
                ]);
                return '<p>Stale edit</p>';
            });
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_responsenotpending', $e->errorcode);
        }

        $row = $DB->get_record('local_forum_ai_pending', ['id' => $other->id], '*', MUST_EXIST);
        $this->assertSame('approved', $row->status);
        $this->assertEquals($postid, $row->postid);
        $this->assertSame($other->message, $row->message);
    }

    /**
     * A second approval of the same row must never publish a second post.
     */
    public function test_second_approval_after_first_publishes_once(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, , $teacher] = $this->create_pending_response();
        $this->setUser($teacher);
        $postcount = $DB->count_records('forum_posts', ['discussion' => $pending->discussionid]);

        approve_response::execute($pending->approval_token, 'approve');

        // The token gate only loads pending rows.
        try {
            approve_response::execute($pending->approval_token, 'approve');
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('alreadysubmitted', $e->errorcode);
        }

        // A request that passed the gate before the first approval finished is
        // refused by the re-read under the lock, before anything is published.
        $published = 0;
        try {
            approval::transition_pending((int) $pending->id, 'approved', (int) $teacher->id, static function () use (&$published) {
                $published++;
            });
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_responsenotpending', $e->errorcode);
        }

        $this->assertSame(0, $published);
        $this->assertSame($postcount + 1, $DB->count_records('forum_posts', ['discussion' => $pending->discussionid]));
    }

    /**
     * A rejection that arrives after an approval keeps the approved row and its post.
     */
    public function test_reject_after_approve_keeps_approved_and_postid(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, , $teacher] = $this->create_pending_response();
        $this->setUser($teacher);

        approve_response::execute($pending->approval_token, 'approve');
        $approved = $DB->get_record('local_forum_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);

        try {
            approve_response::execute($pending->approval_token, 'reject');
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('alreadysubmitted', $e->errorcode);
        }

        try {
            approval::transition_pending((int) $pending->id, 'rejected', (int) $teacher->id);
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_responsenotpending', $e->errorcode);
        }

        $row = $DB->get_record('local_forum_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertSame('approved', $row->status);
        $this->assertNotEmpty($row->postid);
        $this->assertEquals($approved, $row);
    }

    /**
     * A publication failure leaves the row pending and releases the lock for a retry.
     */
    public function test_failed_publication_keeps_row_pending_and_releases_lock(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, , $teacher] = $this->create_pending_response();
        $this->setUser($teacher);

        try {
            approval::transition_pending((int) $pending->id, 'approved', (int) $teacher->id, static function (): void {
                throw new moodle_exception('error_privatereply', 'local_forum_ai');
            });
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_privatereply', $e->errorcode);
        }
        $this->assertSame('pending', $DB->get_field('local_forum_ai_pending', 'status', ['id' => $pending->id]));

        // The lock was released: the retry goes through.
        approve_response::execute($pending->approval_token, 'approve');
        $row = $DB->get_record('local_forum_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertSame('approved', $row->status);
        $this->assertEquals($teacher->id, $row->action_userid);
        $this->assertNotEmpty($row->approved_at);
        $this->assertNotEmpty($row->postid);
    }

    /**
     * Approving a response that another tab already approved must fail with a clear plugin error.
     */
    public function test_approve_already_managed_response_throws_clear_error(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending, , $teacher] = $this->create_pending_response();
        $this->setUser($teacher);

        approve_response::execute($pending->approval_token, 'approve');
        $postcount = $DB->count_records('forum_posts', ['discussion' => $pending->discussionid]);

        try {
            approve_response::execute($pending->approval_token, 'approve');
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertNotInstanceOf(\dml_exception::class, $e);
            $this->assertSame('alreadysubmitted', $e->errorcode);
            $this->assertSame('local_forum_ai', $e->module);
        }

        $this->assertSame($postcount, $DB->count_records('forum_posts', ['discussion' => $pending->discussionid]));
    }

    /**
     * Every token service answers an unknown token with the same clear plugin error.
     */
    public function test_token_services_reject_unknown_token_with_clear_error(): void {
        $this->resetAfterTest();

        [, , $teacher] = $this->create_pending_response();
        $this->setUser($teacher);

        $calls = [
            'approve' => static fn() => approve_response::execute('unknowntoken', 'approve'),
            'reject' => static fn() => approve_response::execute('unknowntoken', 'reject'),
            'update' => static fn() => update_response::execute('unknowntoken', 'Edited message'),
            'details' => static fn() => get_details::execute('unknowntoken'),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("Expected moodle_exception was not thrown for {$name}.");
            } catch (moodle_exception $e) {
                $this->assertNotInstanceOf(\dml_exception::class, $e, $name);
                $this->assertSame('alreadysubmitted', $e->errorcode, $name);
            }
        }
    }

    /**
     * The review page loader reports a managed response as missing, so the page shows its notice.
     */
    public function test_review_loader_returns_null_for_managed_response(): void {
        $this->resetAfterTest();

        [$pending, , $teacher] = $this->create_pending_response();
        $this->setUser($teacher);

        $this->assertNotNull(approval::load_pending_for_user($pending->approval_token, 'pending', IGNORE_MISSING));

        approve_response::execute($pending->approval_token, 'approve');

        $this->assertNull(approval::load_pending_for_user($pending->approval_token, 'pending', IGNORE_MISSING));
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
        $pending->message = '<p>AI-generated reply under review.</p>';
        $pending->status = 'pending';
        $pending->approval_token = approval::generate_approval_token();
        $pending->timecreated = time();
        $pending->timemodified = time();
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);

        return [$pending, $student, $teacher, $course, $cm];
    }
}
