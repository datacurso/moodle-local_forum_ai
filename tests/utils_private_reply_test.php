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
 * Tests for the private-reply handling of local_forum_ai.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

use stdClass;

/**
 * Tests for the is_private_reply helper, the private-reply gates and the thread context filter.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\utils
 */
final class utils_private_reply_test extends \advanced_testcase {
    /**
     * The helper identifies private replies through privatereplyto.
     */
    public function test_is_private_reply(): void {
        $this->resetAfterTest();

        [$forum, $discussion, $course, $cm, $student, $teacher] = $this->create_forum_and_discussion();

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $publicreply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
        ]);
        $privatereply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $teacher->id,
            'privatereplyto' => $student->id,
        ]);

        $this->assertFalse(utils::is_private_reply($publicreply));
        $this->assertTrue(utils::is_private_reply($privatereply));
    }

    /**
     * The post task must bail out when the triggering post is a private reply.
     *
     * @covers \local_forum_ai\task\process_ai_post
     */
    public function test_process_ai_post_skips_private_reply(): void {
        global $DB;

        $this->resetAfterTest();

        [$forum, $discussion, $course, $cm, $student, $teacher] = $this->create_forum_and_discussion();

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $privatereply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $teacher->id,
            'privatereplyto' => $student->id,
        ]);

        $this->set_forum_config($forum->id);

        $postcount = $DB->count_records('forum_posts');

        $task = new task\process_ai_post();
        $task->set_custom_data([
            'postid' => $privatereply->id,
            'cmid' => $cm->id,
        ]);

        $this->expectOutputRegex('/private reply/');
        $task->execute();

        $this->assertSame(0, $DB->count_records('local_forum_ai_pending'));
        $this->assertSame($postcount, $DB->count_records('forum_posts'));
    }

    /**
     * The observer must skip private replies in both immediate and delayed modes.
     *
     * @covers \local_forum_ai\observer\post
     */
    public function test_observer_skips_private_reply(): void {
        global $DB;

        $this->resetAfterTest();

        [$forum, $discussion, $course, $cm, $student, $teacher] = $this->create_forum_and_discussion();

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $privatereply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $teacher->id,
            'privatereplyto' => $student->id,
        ]);

        $event = \mod_forum\event\post_created::create([
            'context' => \context_module::instance($cm->id),
            'objectid' => $privatereply->id,
            'other' => [
                'discussionid' => $discussion->id,
                'forumid' => $forum->id,
                'forumtype' => $forum->type,
            ],
        ]);

        // Immediate mode: no adhoc task may be queued.
        $this->set_forum_config($forum->id, ['require_approval' => 1, 'usedelay' => 0]);
        observer\post::post_created($event);
        $this->assertSame(0, $DB->count_records('task_adhoc', ['component' => 'local_forum_ai']));

        // Delayed mode: no queue row may be created.
        $this->set_forum_config($forum->id, ['require_approval' => 0, 'usedelay' => 1, 'delayminutes' => 30]);
        observer\post::post_created($event);
        $this->assertSame(0, $DB->count_records('local_forum_ai_queue'));
    }

    /**
     * Thread context sent to the AI service must exclude private replies.
     */
    public function test_build_thread_context_excludes_private_replies(): void {
        $this->resetAfterTest();

        [$forum, $discussion, $course, $cm, $student, $teacher] = $this->create_forum_and_discussion();

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $publicreply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
            'message' => 'Public insight from a classmate',
        ]);
        $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $teacher->id,
            'privatereplyto' => $student->id,
            'message' => 'Private guidance for one student only',
        ]);
        $currentpost = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $publicreply->id,
            'userid' => $student->id,
            'message' => 'Current post asking a question',
        ]);

        $context = utils::build_thread_context((int) $discussion->id, (int) $currentpost->id);

        $messages = implode(' | ', array_column($context, 'message'));
        $this->assertStringContainsString('Public insight from a classmate', $messages);
        $this->assertStringNotContainsString('Private guidance', $messages);
    }

    /**
     * The review payload for a user must exclude their private replies.
     */
    public function test_build_forum_ai_payload_excludes_private_replies(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forummodule->id, $course->id, false, MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');

        // One student post per discussion: a public answer and a private reply.
        $publicdiscussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forummodule->id,
            'userid' => $teacher->id,
        ]);
        $forumgenerator->create_post([
            'discussion' => $publicdiscussion->id,
            'parent' => $publicdiscussion->firstpost,
            'userid' => $student->id,
            'message' => 'Public homework answer',
        ]);

        $privatediscussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forummodule->id,
            'userid' => $teacher->id,
        ]);
        $forumgenerator->create_post([
            'discussion' => $privatediscussion->id,
            'parent' => $privatediscussion->firstpost,
            'userid' => $student->id,
            'privatereplyto' => $teacher->id,
            'message' => 'Private note to the teacher',
        ]);

        $payload = utils::build_forum_ai_payload((int) $cm->id, (int) $student->id);

        $discussions = $payload['forum_participations'][0]['participation']['discussions'];
        $answers = implode(' | ', array_column($discussions, 'answer'));
        $this->assertStringContainsString('Public homework answer', $answers);
        $this->assertStringNotContainsString('Private note to the teacher', $answers);
    }

    /**
     * A public reply still travels through the whole gate chain.
     *
     * The AI client has no injection seam; with no license key it fails fast
     * before any network activity, so reaching that failure proves every gate
     * (including the private-reply one) let the public post through.
     *
     * @covers \local_forum_ai\task\process_ai_post
     */
    public function test_process_ai_post_processes_public_reply(): void {
        global $DB;

        $this->resetAfterTest();

        [$forum, $discussion, $course, $cm, $student] = $this->create_forum_and_discussion();

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $publicreply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
        ]);

        $this->set_forum_config($forum->id);
        unset_config('licensekey', 'aiprovider_datacurso');

        $task = new task\process_ai_post();
        $task->set_custom_data([
            'postid' => $publicreply->id,
            'cmid' => $cm->id,
        ]);

        $thrown = null;
        try {
            $task->execute();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        // Swallow the provider's internal debugging without pinning its count.
        $this->resetDebugging();

        // The task rethrows the AI client failure, proving every gate let the post through:
        // the gates bail out with mtrace + return and never reach the AI client.
        $this->assertNotNull($thrown, 'The AI client was expected to fail without a configured license key.');
        $this->assertDoesNotMatchRegularExpression('/private reply/', $this->getActualOutput());
        $this->assertSame(0, $DB->count_records('local_forum_ai_pending'));
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
}
