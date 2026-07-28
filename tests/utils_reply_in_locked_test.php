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
 * Tests for the reply-in-locked-discussions helpers of local_forum_ai.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

use stdClass;

/**
 * Tests for utils reply-in-locked helpers and the locked-discussion generation gate.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\utils
 */
final class utils_reply_in_locked_test extends \advanced_testcase {
    /**
     * The global default must be false when unset, empty or zero, and true when enabled.
     */
    public function test_get_default_reply_in_locked(): void {
        $this->resetAfterTest();

        unset_config('default_replyinlocked', 'local_forum_ai');
        $this->assertFalse(utils::get_default_reply_in_locked());

        set_config('default_replyinlocked', '', 'local_forum_ai');
        $this->assertFalse(utils::get_default_reply_in_locked());

        set_config('default_replyinlocked', 0, 'local_forum_ai');
        $this->assertFalse(utils::get_default_reply_in_locked());

        set_config('default_replyinlocked', 1, 'local_forum_ai');
        $this->assertTrue(utils::get_default_reply_in_locked());
    }

    /**
     * The effective value must fall back to the global default and honour per-forum overrides.
     */
    public function test_get_effective_reply_in_locked(): void {
        $this->resetAfterTest();

        // No config row at all: global default applies.
        unset_config('default_replyinlocked', 'local_forum_ai');
        $this->assertFalse(utils::get_effective_reply_in_locked(null));

        set_config('default_replyinlocked', 1, 'local_forum_ai');
        $this->assertTrue(utils::get_effective_reply_in_locked(null));

        // Empty config object (task fallback): global default applies too.
        $this->assertTrue(utils::get_effective_reply_in_locked(new stdClass()));

        // Per-forum value overrides the global in both directions.
        $config = new stdClass();
        $config->replyinlocked = 0;
        $this->assertFalse(utils::get_effective_reply_in_locked($config));

        set_config('default_replyinlocked', 0, 'local_forum_ai');
        $config->replyinlocked = 1;
        $this->assertTrue(utils::get_effective_reply_in_locked($config));
    }

    /**
     * Unlocked discussions always allow AI replies, whatever the option value.
     */
    public function test_can_reply_in_discussion_unlocked(): void {
        $this->resetAfterTest();

        [$forum, $discussion] = $this->create_forum_and_discussion();

        set_config('default_replyinlocked', 0, 'local_forum_ai');
        $this->assertTrue(utils::can_reply_in_discussion($forum, $discussion, null));

        $config = new stdClass();
        $config->replyinlocked = 0;
        $this->assertTrue(utils::can_reply_in_discussion($forum, $discussion, $config));

        $config->replyinlocked = 1;
        $this->assertTrue(utils::can_reply_in_discussion($forum, $discussion, $config));
    }

    /**
     * Manually locked discussions only allow AI replies when the option is enabled.
     */
    public function test_can_reply_in_discussion_manual_lock(): void {
        global $DB;

        $this->resetAfterTest();

        [$forum, $discussion] = $this->create_forum_and_discussion();

        // Lock the discussion manually.
        $DB->set_field('forum_discussions', 'timelocked', time() - 10, ['id' => $discussion->id]);
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        // Option disabled via per-forum config: no reply allowed.
        $config = new stdClass();
        $config->replyinlocked = 0;
        set_config('default_replyinlocked', 1, 'local_forum_ai');
        $this->assertFalse(utils::can_reply_in_discussion($forum, $discussion, $config));

        // Option enabled via per-forum config: reply allowed.
        $config->replyinlocked = 1;
        set_config('default_replyinlocked', 0, 'local_forum_ai');
        $this->assertTrue(utils::can_reply_in_discussion($forum, $discussion, $config));

        // Option controlled by the global default when there is no per-forum value.
        set_config('default_replyinlocked', 1, 'local_forum_ai');
        $this->assertTrue(utils::can_reply_in_discussion($forum, $discussion, null));

        set_config('default_replyinlocked', 0, 'local_forum_ai');
        $this->assertFalse(utils::can_reply_in_discussion($forum, $discussion, null));
    }

    /**
     * Discussions locked by the forum inactivity rule are detected through the core API.
     */
    public function test_can_reply_in_discussion_inactivity_lock(): void {
        global $DB;

        $this->resetAfterTest();

        [$forum, $discussion] = $this->create_forum_and_discussion(['lockdiscussionafter' => DAYSECS]);

        // Make the discussion inactive for longer than the lock threshold.
        $DB->set_field('forum_discussions', 'timemodified', time() - (2 * DAYSECS), ['id' => $discussion->id]);
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        set_config('default_replyinlocked', 0, 'local_forum_ai');
        $this->assertFalse(utils::can_reply_in_discussion($forum, $discussion, null));

        $config = new stdClass();
        $config->replyinlocked = 1;
        $this->assertTrue(utils::can_reply_in_discussion($forum, $discussion, $config));
    }

    /**
     * The generation task must bail out on locked discussions when the option is disabled.
     *
     * @covers \local_forum_ai\task\process_ai_post
     */
    public function test_process_ai_post_skips_locked_discussion(): void {
        global $DB;

        $this->resetAfterTest();

        [$forum, $discussion, $course, $cm, $student] = $this->create_forum_and_discussion([], true);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $reply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
        ]);

        // AI enabled for the forum, but locked discussions are excluded.
        $this->set_forum_config($forum->id, 0);

        // Lock the discussion manually.
        $DB->set_field('forum_discussions', 'timelocked', time() - 10, ['id' => $discussion->id]);

        $postcount = $DB->count_records('forum_posts');

        $task = new task\process_ai_post();
        $task->set_custom_data([
            'postid' => $reply->id,
            'cmid' => $cm->id,
        ]);

        $this->expectOutputRegex('/is locked/');
        $task->execute();

        $this->assertSame(0, $DB->count_records('local_forum_ai_pending'));
        $this->assertSame($postcount, $DB->count_records('forum_posts'));
    }

    /**
     * The discussion task must bail out on locked discussions when the option is disabled.
     *
     * @covers \local_forum_ai\task\process_ai_discussion
     */
    public function test_process_ai_discussion_skips_locked_discussion(): void {
        global $DB;

        $this->resetAfterTest();

        [$forum, $discussion, $course, $cm, $student] = $this->create_forum_and_discussion([], true);

        // AI and initial replies enabled for the forum, but locked discussions are excluded.
        $this->set_forum_config($forum->id, 0, 1);

        // Lock the discussion manually.
        $DB->set_field('forum_discussions', 'timelocked', time() - 10, ['id' => $discussion->id]);

        $postcount = $DB->count_records('forum_posts');

        $task = new task\process_ai_discussion();
        $task->set_custom_data([
            'discussionid' => $discussion->id,
            'cmid' => $cm->id,
        ]);

        $this->expectOutputRegex('/is locked/');
        $task->execute();

        $this->assertSame(0, $DB->count_records('local_forum_ai_pending'));
        $this->assertSame($postcount, $DB->count_records('forum_posts'));
    }

    /**
     * Creates or updates the plugin config row for a forum.
     *
     * @param int $forumid Forum ID.
     * @param int $replyinlocked Per-forum value for the reply-in-locked option.
     * @param int $enablediainitconversation Whether the AI replies to the initial discussion post.
     */
    private function set_forum_config(int $forumid, int $replyinlocked, int $enablediainitconversation = 0): void {
        global $DB;

        $configrow = $DB->get_record('local_forum_ai_config', ['forumid' => $forumid]) ?: new stdClass();
        $configrow->forumid = $forumid;
        $configrow->enabled = 1;
        $configrow->replyinlocked = $replyinlocked;
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
