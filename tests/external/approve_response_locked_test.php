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
 * Tests for the locked-discussion gate in the approve_response external function.
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
use moodle_exception;
use stdClass;

global $CFG;

require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests for approve_response behaviour on locked discussions.
 *
 * Covers: MDL-INT-014 — Barreras de publicacion: fecha limite, bloqueo y respuestas privadas
 * Covers: MDL-INT-024 — Permisos y validacion de contexto en los servicios web
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\external\approve_response
 */
final class approve_response_locked_test extends externallib_advanced_testcase {
    /**
     * Approving while the discussion is locked and the option is disabled must fail.
     */
    public function test_approve_fails_when_discussion_locked_and_option_disabled(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending] = $this->create_locked_pending_response(0);

        $this->setAdminUser();

        try {
            approve_response::execute($pending->approval_token, 'approve');
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_discussionlocked', $e->errorcode);
        }

        // The pending row must remain untouched so it can be approved after unlocking.
        $this->assertSame(
            'pending',
            $DB->get_field('local_forum_ai_pending', 'status', ['id' => $pending->id], MUST_EXIST)
        );
    }

    /**
     * Approving while locked must succeed when the per-forum option is enabled.
     */
    public function test_approve_succeeds_when_option_enabled(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending] = $this->create_locked_pending_response(1);

        $this->setAdminUser();

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
     * Rejecting must stay allowed even while the discussion is locked.
     */
    public function test_reject_succeeds_while_discussion_locked(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending] = $this->create_locked_pending_response(0);

        $this->setAdminUser();

        $result = approve_response::execute($pending->approval_token, 'reject');
        $result = external_api::clean_returnvalue(approve_response::execute_returns(), $result);

        $this->assertTrue($result['success']);
        $this->assertSame(
            'rejected',
            $DB->get_field('local_forum_ai_pending', 'status', ['id' => $pending->id], MUST_EXIST)
        );
    }

    /**
     * Creates a locked discussion with a pending AI response awaiting approval.
     *
     * @param int $replyinlocked Per-forum value for the reply-in-locked option.
     * @return array [$pending, $forum, $discussion].
     */
    private function create_locked_pending_response(int $replyinlocked): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

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

        $configrow = $DB->get_record('local_forum_ai_config', ['forumid' => $forum->id]) ?: new stdClass();
        $configrow->forumid = $forum->id;
        $configrow->enabled = 1;
        $configrow->replyinlocked = $replyinlocked;
        $configrow->require_approval = 1;
        $configrow->reply_message = 'Test prompt';
        $configrow->timemodified = time();

        if (empty($configrow->id)) {
            $configrow->timecreated = time();
            $DB->insert_record('local_forum_ai_config', $configrow);
        } else {
            $DB->update_record('local_forum_ai_config', $configrow);
        }

        $pending = new stdClass();
        $pending->discussionid = $discussion->id;
        $pending->forumid = $forum->id;
        $pending->parentpostid = $reply->id;
        $pending->creator_userid = $student->id;
        $pending->subject = 'Re: ' . $discussion->name;
        $pending->message = 'AI-generated reply awaiting approval.';
        $pending->status = 'pending';
        $pending->approval_token = md5(uniqid('locktest_', true));
        $pending->timecreated = time();
        $pending->timemodified = time();
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);

        // Lock the discussion after the pending response was generated.
        $DB->set_field('forum_discussions', 'timelocked', time() - 10, ['id' => $discussion->id]);

        return [$pending, $forum, $discussion];
    }
}
