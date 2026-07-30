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
 * Tests for the private-reply gate in the approve_response external function.
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
 * Tests for approve_response behaviour when the parent post is a private reply.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\external\approve_response
 */
final class approve_response_private_test extends externallib_advanced_testcase {
    /**
     * Approving a pending row whose parent is a private reply must fail cleanly.
     */
    public function test_approve_fails_when_parent_is_private_reply(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending] = $this->create_pending_response(true);

        $this->setAdminUser();

        try {
            approve_response::execute($pending->approval_token, 'approve');
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_privatereply', $e->errorcode);
        }

        $this->assertSame(
            'pending',
            $DB->get_field('local_forum_ai_pending', 'status', ['id' => $pending->id], MUST_EXIST)
        );
    }

    /**
     * Rejecting must stay allowed when the parent is a private reply.
     */
    public function test_reject_succeeds_when_parent_is_private_reply(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending] = $this->create_pending_response(true);

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
     * A pending row with a public parent publishes normally.
     */
    public function test_approve_succeeds_with_public_parent(): void {
        global $DB;

        $this->resetAfterTest();

        [$pending] = $this->create_pending_response(false);

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
     * Creates a pending AI response whose parent post is private or public.
     *
     * @param bool $privateparent Whether the parent post is a private reply.
     * @return array [$pending, $forum, $discussion].
     */
    private function create_pending_response(bool $privateparent): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
        ]);
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $parentoptions = [
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $privateparent ? $teacher->id : $student->id,
        ];
        if ($privateparent) {
            $parentoptions['privatereplyto'] = $student->id;
        }
        $parent = $forumgenerator->create_post($parentoptions);

        $configrow = $DB->get_record('local_forum_ai_config', ['forumid' => $forum->id]) ?: new stdClass();
        $configrow->forumid = $forum->id;
        $configrow->enabled = 1;
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
        $pending->parentpostid = $parent->id;
        $pending->creator_userid = $student->id;
        $pending->subject = 'Re: ' . $discussion->name;
        $pending->message = 'AI-generated reply awaiting approval.';
        $pending->status = 'pending';
        $pending->approval_token = md5(uniqid('privatetest_', true));
        $pending->timecreated = time();
        $pending->timemodified = time();
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);

        return [$pending, $forum, $discussion];
    }
}
