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
 * Tests for the forum cut-off date gate of local_forum_ai.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

use stdClass;

/**
 * Tests for the is_forum_cutoff_reached helper and the cut-off generation gates.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\utils
 */
final class utils_cutoff_test extends \advanced_testcase {
    /**
     * The helper follows the forum cut-off date through the core API.
     */
    public function test_is_forum_cutoff_reached(): void {
        $this->resetAfterTest();

        // Cut-off date in the past: reached.
        [$forum] = $this->create_forum_and_discussion(['cutoffdate' => time() - DAYSECS]);
        $this->assertTrue(utils::is_forum_cutoff_reached($forum));

        // Cut-off date in the future: not reached.
        [$forum] = $this->create_forum_and_discussion(['cutoffdate' => time() + DAYSECS]);
        $this->assertFalse(utils::is_forum_cutoff_reached($forum));

        // No cut-off date configured: never reached.
        [$forum] = $this->create_forum_and_discussion(['cutoffdate' => 0]);
        $this->assertFalse(utils::is_forum_cutoff_reached($forum));
    }

    /**
     * The post task must bail out when the forum cut-off date has passed.
     *
     * @covers \local_forum_ai\task\process_ai_post
     */
    public function test_process_ai_post_skips_cutoff_forum(): void {
        global $DB;

        $this->resetAfterTest();

        [$forum, $discussion, $course, $cm, $student] =
            $this->create_forum_and_discussion(['cutoffdate' => time() - DAYSECS], true);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $reply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
        ]);

        $this->set_forum_config($forum->id);

        $postcount = $DB->count_records('forum_posts');

        $task = new task\process_ai_post();
        $task->set_custom_data([
            'postid' => $reply->id,
            'cmid' => $cm->id,
        ]);

        $this->expectOutputRegex('/cut-off/');
        $task->execute();

        $this->assertSame(0, $DB->count_records('local_forum_ai_pending'));
        $this->assertSame($postcount, $DB->count_records('forum_posts'));
    }

    /**
     * The discussion task must bail out when the forum cut-off date has passed.
     *
     * @covers \local_forum_ai\task\process_ai_discussion
     */
    public function test_process_ai_discussion_skips_cutoff_forum(): void {
        global $DB;

        $this->resetAfterTest();

        [$forum, $discussion, $course, $cm, $student] =
            $this->create_forum_and_discussion(['cutoffdate' => time() - DAYSECS], true);

        $this->set_forum_config($forum->id, 1);

        $postcount = $DB->count_records('forum_posts');

        $task = new task\process_ai_discussion();
        $task->set_custom_data([
            'discussionid' => $discussion->id,
            'cmid' => $cm->id,
        ]);

        $this->expectOutputRegex('/cut-off/');
        $task->execute();

        $this->assertSame(0, $DB->count_records('local_forum_ai_pending'));
        $this->assertSame($postcount, $DB->count_records('forum_posts'));
    }

    /**
     * Creates or updates the plugin config row for a forum.
     *
     * @param int $forumid Forum ID.
     * @param int $enablediainitconversation Whether the AI replies to the initial discussion post.
     */
    private function set_forum_config(int $forumid, int $enablediainitconversation = 0): void {
        global $DB;

        $configrow = $DB->get_record('local_forum_ai_config', ['forumid' => $forumid]) ?: new stdClass();
        $configrow->forumid = $forumid;
        $configrow->enabled = 1;
        $configrow->require_approval = 1;
        $configrow->enablediainitconversation = $enablediainitconversation;
        $configrow->reply_message = 'Test prompt';
        $configrow->timemodified = time();

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
     * @param array $forumoptions Extra options for the forum instance.
     * @param bool $extended Whether to also return course, cm and student records.
     * @return array [$forum, $discussion] or [$forum, $discussion, $course, $cm, $student].
     */
    private function create_forum_and_discussion(array $forumoptions = [], bool $extended = false): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $forummodule = $this->getDataGenerator()->create_module(
            'forum',
            array_merge(['course' => $course->id], $forumoptions)
        );
        $cm = get_coursemodule_from_instance('forum', $forummodule->id, $course->id, false, MUST_EXIST);
        $forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
        ]);
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        if ($extended) {
            return [$forum, $discussion, $course, $cm, $student];
        }

        return [$forum, $discussion];
    }
}
