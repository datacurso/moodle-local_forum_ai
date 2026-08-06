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
 * Tests excluding deleted posts from the AI payloads.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

/**
 * Deleted posts are content a normal participant cannot see: they must never
 * travel to the external AI service, neither in the thread context nor in the
 * whole-forum participation payload.
 *
 * Covers: MDL-INT-035 — Construccion del contexto del hilo para la IA
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\utils
 */
final class utils_deleted_posts_test extends \advanced_testcase {
    /**
     * Marks a forum post as deleted.
     *
     * @param int $postid Post id.
     */
    private function mark_deleted(int $postid): void {
        global $DB;

        $DB->set_field('forum_posts', 'deleted', 1, ['id' => $postid]);
    }

    /**
     * The thread context must exclude posts marked as deleted.
     */
    public function test_build_thread_context_excludes_deleted_posts(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $teacher->id,
            'message' => 'Root topic message',
        ]);
        $visiblereply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
            'message' => 'Visible classmate reply',
        ]);
        $deletedreply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
            'message' => 'Moderated content removed by a teacher',
        ]);
        $this->mark_deleted((int)$deletedreply->id);
        $currentpost = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $visiblereply->id,
            'userid' => $student->id,
            'message' => 'Current post',
        ]);

        $context = utils::build_thread_context((int)$discussion->id, (int)$currentpost->id);

        $messages = implode(' | ', array_column($context, 'message'));
        $this->assertStringContainsString('Root topic message', $messages);
        $this->assertStringContainsString('Visible classmate reply', $messages);
        $this->assertStringNotContainsString('Moderated content', $messages);
    }

    /**
     * The whole-forum participation payload must exclude deleted posts.
     */
    public function test_build_forum_ai_payload_excludes_deleted_posts(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $teacher->id,
        ]);
        $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
            'message' => 'Public homework answer',
        ]);
        $deletedpost = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
            'message' => 'Removed inappropriate answer',
        ]);
        $this->mark_deleted((int)$deletedpost->id);

        $payload = utils::build_forum_ai_payload($cm->id, (int)$student->id);
        $answers = implode(' | ', array_column(
            $payload['forum_participations'][0]['participation']['discussions'],
            'answer'
        ));

        $this->assertStringContainsString('Public homework answer', $answers);
        $this->assertStringNotContainsString('Removed inappropriate answer', $answers);
    }
}
