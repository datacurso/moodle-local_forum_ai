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
 * Tests for the scoped, traceable expired-pendings cleanup of local_forum_ai.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

use moodle_exception;
use stdClass;

global $CFG;

require_once($CFG->dirroot . '/local/forum_ai/locallib.php');

/**
 * Tests that the cleanup is course/forum scoped and leaves traceable 'expired' rows.
 *
 * Covers: MDL-INT-020 — Expiracion automatica de respuestas pendientes vencidas
 * Covers: MDL-INT-018 — Conteo de preguntas guia por hilo
 *
 * @group local_forum_ai
 * @covers ::local_forum_ai_cleanup_expired
 * @covers ::local_forum_ai_get_history
 */
final class cleanup_expired_test extends \advanced_testcase {
    /**
     * Cleaning one course must never touch another course's rows.
     */
    public function test_cleanup_scopes_to_course(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $seta = $this->create_setup(['cutoffdate' => time() - DAYSECS]);
        $setb = $this->create_setup(['cutoffdate' => time() - DAYSECS]);

        $count = local_forum_ai_cleanup_expired($seta->course->id);

        $this->assertSame(1, $count);
        $this->assertSame(
            'expired',
            $DB->get_field('local_forum_ai_pending', 'status', ['id' => $seta->pendingid], MUST_EXIST)
        );
        $this->assertSame(
            'pending',
            $DB->get_field('local_forum_ai_pending', 'status', ['id' => $setb->pendingid], MUST_EXIST)
        );
    }

    /**
     * A forum id further restricts the cleanup within the course.
     */
    public function test_cleanup_scopes_to_forum(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $setx = $this->create_setup(['cutoffdate' => time() - DAYSECS]);
        $sety = $this->create_setup(['cutoffdate' => time() - DAYSECS], $setx->course);

        $count = local_forum_ai_cleanup_expired($setx->course->id, (int) $setx->forum->id);

        $this->assertSame(1, $count);
        $this->assertSame(
            'expired',
            $DB->get_field('local_forum_ai_pending', 'status', ['id' => $setx->pendingid], MUST_EXIST)
        );
        $this->assertSame(
            'pending',
            $DB->get_field('local_forum_ai_pending', 'status', ['id' => $sety->pendingid], MUST_EXIST)
        );
    }

    /**
     * The expiry criterion: cut-off date, or due date only when no cut-off is set.
     */
    public function test_cleanup_criterion_matrix(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cutoffpast = $this->create_setup(['cutoffdate' => time() - DAYSECS], $course);
        $duedatepast = $this->create_setup(['cutoffdate' => 0, 'duedate' => time() - DAYSECS], $course);
        $cutofffuture = $this->create_setup(['cutoffdate' => time() + DAYSECS], $course);
        $nodates = $this->create_setup(['cutoffdate' => 0, 'duedate' => 0], $course);
        $cutoffwins = $this->create_setup(
            ['cutoffdate' => time() + DAYSECS, 'duedate' => time() - DAYSECS],
            $course
        );

        $count = local_forum_ai_cleanup_expired($course->id);

        $this->assertSame(2, $count);
        $expected = [
            $cutoffpast->pendingid => 'expired',
            $duedatepast->pendingid => 'expired',
            $cutofffuture->pendingid => 'pending',
            $nodates->pendingid => 'pending',
            $cutoffwins->pendingid => 'pending',
        ];
        foreach ($expected as $pendingid => $status) {
            $this->assertSame(
                $status,
                $DB->get_field('local_forum_ai_pending', 'status', ['id' => $pendingid], MUST_EXIST),
                "Unexpected status for pending row {$pendingid}."
            );
        }
    }

    /**
     * Expired rows leave the pending list and appear in the history.
     */
    public function test_expired_rows_reach_history(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup(['cutoffdate' => time() - DAYSECS]);

        local_forum_ai_cleanup_expired($setup->course->id);

        $history = local_forum_ai_get_history($setup->course->id);
        $this->assertArrayHasKey($setup->pendingid, $history);
        $this->assertSame('expired', $history[$setup->pendingid]->status);

        $pending = local_forum_ai_get_pending($setup->course->id);
        $this->assertArrayNotHasKey($setup->pendingid, $pending);
    }

    /**
     * Expired rows can no longer be approved or edited.
     *
     * @covers \local_forum_ai\external\approve_response
     * @covers \local_forum_ai\external\update_response
     */
    public function test_guards_reject_expired_rows(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup(['cutoffdate' => time() - DAYSECS]);
        local_forum_ai_cleanup_expired($setup->course->id);
        $token = $DB->get_field('local_forum_ai_pending', 'approval_token', ['id' => $setup->pendingid], MUST_EXIST);

        // Approval loads by status = 'pending': an expired token is not found.
        try {
            external\approve_response::execute($token, 'approve');
            $this->fail('Expected dml_missing_record_exception was not thrown.');
        } catch (\dml_missing_record_exception $e) {
            $this->assertSame('invalidrecord', $e->errorcode);
        }

        // Editing a non-pending row is rejected explicitly.
        try {
            external\update_response::execute($token, 'Tampered message');
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_responsenotpending', $e->errorcode);
        }
    }

    /**
     * Expired responses never count as used follow-up question turns.
     *
     * @covers \local_forum_ai\utils::count_prior_ai_turns_in_thread
     */
    public function test_count_prior_ai_turns_excludes_expired(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup(['cutoffdate' => 0]);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $reply = $forumgenerator->create_post([
            'discussion' => $setup->discussion->id,
            'parent' => $setup->discussion->firstpost,
            'userid' => $setup->student->id,
        ]);

        // The fixture pending row replies to the first post: while pending it counts.
        $this->assertSame(
            1,
            utils::count_prior_ai_turns_in_thread((int) $setup->discussion->id, (int) $reply->id)
        );

        // Once expired it must not count (before traceability those rows were deleted).
        $DB->set_field('local_forum_ai_pending', 'status', 'expired', ['id' => $setup->pendingid]);
        $this->assertSame(
            0,
            utils::count_prior_ai_turns_in_thread((int) $setup->discussion->id, (int) $reply->id)
        );
    }

    /**
     * Creates a course (or reuses one), a forum, a discussion and a pending row.
     *
     * @param array $forumoptions Extra forum options (cutoffdate/duedate pass through).
     * @param stdClass|null $course Course to reuse; a new one is created when null.
     * @return stdClass Setup holder (course, student, forum, discussion, pendingid).
     */
    private function create_setup(array $forumoptions, ?stdClass $course = null): stdClass {
        global $DB;

        $setup = new stdClass();
        $setup->course = $course ?? $this->getDataGenerator()->create_course();
        $setup->student = $this->getDataGenerator()->create_and_enrol($setup->course, 'student');

        $forummodule = $this->getDataGenerator()->create_module(
            'forum',
            array_merge(['course' => $setup->course->id], $forumoptions)
        );
        $setup->forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $setup->course->id,
            'forum' => $setup->forum->id,
            'userid' => $setup->student->id,
        ]);
        $setup->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $pending = new stdClass();
        $pending->discussionid = $setup->discussion->id;
        $pending->forumid = $setup->forum->id;
        $pending->parentpostid = $setup->discussion->firstpost;
        $pending->creator_userid = $setup->student->id;
        $pending->subject = 'Re: ' . $setup->discussion->name;
        $pending->message = '<p>AI reply awaiting approval</p>';
        $pending->status = 'pending';
        $pending->approval_token = md5(uniqid('expired_', true));
        $pending->timecreated = time();
        $pending->timemodified = time();
        $setup->pendingid = (int) $DB->insert_record('local_forum_ai_pending', $pending);

        return $setup;
    }
}
