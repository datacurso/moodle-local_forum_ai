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
 * Tests for the sanitization of AI-generated messages.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/xss_payload_fixture.php');

/**
 * Tests that AI responses are cleaned before being stored or published.
 *
 * The AI service response is external, untrusted content: script payloads
 * must be neutralized (not rejected) while legitimate formatting survives.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\approval
 */
final class sanitization_test extends \advanced_testcase {
    /**
     * The pending row must store a cleaned message that keeps legitimate formatting.
     */
    public function test_create_approval_request_sanitizes_message(): void {
        global $DB;

        $this->resetAfterTest();

        [$forum, $discussion] = $this->create_forum_and_discussion();

        approval::create_approval_request($discussion, $forum, xss_payload_fixture::PAYLOAD);

        $pending = $DB->get_record('local_forum_ai_pending', ['discussionid' => $discussion->id], '*', MUST_EXIST);

        $this->assertStringNotContainsString('<script', $pending->message);
        $this->assertStringNotContainsString('onerror', $pending->message);
        $this->assertStringContainsString('<p>', $pending->message);
        $this->assertStringContainsString('<strong>', $pending->message);
        $this->assertStringContainsString('<li>', $pending->message);
    }

    /**
     * Auto-published replies must be cleaned and never flagged as trusted content.
     */
    public function test_create_ai_reply_sanitizes_message(): void {
        global $DB;

        $this->resetAfterTest();

        [$forum, $discussion, $student] = $this->create_forum_and_discussion();

        $result = approval::create_ai_reply(
            $discussion,
            xss_payload_fixture::PAYLOAD,
            (int)$discussion->firstpost,
            (int)$student->id
        );
        $this->assertTrue($result);

        $post = $DB->get_record(
            'forum_posts',
            ['discussion' => $discussion->id, 'parent' => $discussion->firstpost],
            '*',
            MUST_EXIST
        );

        $this->assertStringNotContainsString('<script', $post->message);
        $this->assertStringNotContainsString('onerror', $post->message);
        $this->assertStringContainsString('<p>', $post->message);
        $this->assertStringContainsString('<strong>', $post->message);
        $this->assertStringContainsString('<li>', $post->message);
        $this->assertEquals(0, $post->messagetrust);
    }

    /**
     * Creates a course, a forum and one discussion started by a student.
     *
     * No teacher is enrolled, so the pending-notification path resolves to
     * an empty recipient list and no message is sent during the tests.
     *
     * @return array [$forum, $discussion, $student].
     */
    private function create_forum_and_discussion(): array {
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

        return [$forum, $discussion, $student];
    }
}
