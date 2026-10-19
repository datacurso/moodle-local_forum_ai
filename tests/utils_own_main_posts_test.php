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
 * Tests for utils::get_user_main_posts.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

use local_forum_ai\local\payload_pseudonymizer;
use stdClass;

/**
 * The student's own main posts in other discussions are sent to the AI so it never
 * asks for a post that already exists (report 3).
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\utils::get_user_main_posts
 */
final class utils_own_main_posts_test extends \advanced_testcase {
    /**
     * Only the user's own parent posts of other discussions are returned, pseudonymised.
     */
    public function test_returns_own_main_posts_of_other_discussions(): void {
        $this->resetAfterTest();
        $f = $this->create_fixture();
        $other = $this->add_discussion($f, $f->student, 'My unit summary');
        $this->add_discussion($f, $f->teacher, 'Teacher topic');

        $entries = utils::get_user_main_posts(
            (int) $f->forum->id,
            (int) $f->student->id,
            time() + 10,
            new payload_pseudonymizer(),
            (int) $f->discussion->id,
        );

        $this->assertCount(1, $entries);
        $this->assertSame((int) $other->firstpost, $entries[0]['id']);
        $this->assertSame(1, $entries[0]['order']);
        $this->assertSame('My unit summary', $entries[0]['message']);
    }

    /**
     * The current discussion is skipped: its posts already travel in the thread history.
     */
    public function test_skips_current_discussion(): void {
        $this->resetAfterTest();
        $f = $this->create_fixture();

        $entries = utils::get_user_main_posts(
            (int) $f->forum->id,
            (int) $f->student->id,
            time() + 10,
            null,
            (int) $f->discussion->id,
        );

        $this->assertSame([], $entries);
    }

    /**
     * Replies, deleted posts and private replies never count as main posts.
     */
    public function test_excludes_replies_deleted_and_private_posts(): void {
        global $DB;

        $this->resetAfterTest();
        $f = $this->create_fixture();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $deleted = $this->add_discussion($f, $f->student, 'Deleted main post');
        $DB->set_field('forum_posts', 'deleted', 1, ['id' => $deleted->firstpost]);
        $private = $this->add_discussion($f, $f->student, 'Private main post');
        $DB->set_field('forum_posts', 'privatereplyto', $f->teacher->id, ['id' => $private->firstpost]);
        $visible = $this->add_discussion($f, $f->teacher, 'Teacher topic');
        $generator->create_post([
            'discussion' => $visible->id,
            'parent' => $visible->firstpost,
            'userid' => $f->student->id,
            'message' => 'Just a reply',
        ]);

        $entries = utils::get_user_main_posts((int) $f->forum->id, (int) $f->student->id, time() + 10);

        // Only the opening post of the fixture discussion remains.
        $this->assertSame(['Opening topic message'], array_column($entries, 'message'));
    }

    /**
     * Posts created after the cutoff time are left out.
     */
    public function test_respects_the_cutoff_time(): void {
        global $DB;

        $this->resetAfterTest();
        $f = $this->create_fixture();
        $late = $this->add_discussion($f, $f->student, 'Posted later');
        $DB->set_field('forum_posts', 'created', time() + 1000, ['id' => $late->firstpost]);

        $entries = utils::get_user_main_posts((int) $f->forum->id, (int) $f->student->id, time() + 10);

        $this->assertNotContains('Posted later', array_column($entries, 'message'));
    }

    /**
     * Posts of other forums are not included.
     */
    public function test_ignores_other_forums(): void {
        $this->resetAfterTest();
        $f = $this->create_fixture();
        $otherforum = $this->getDataGenerator()->create_module('forum', ['course' => $f->course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $f->course->id,
            'forum' => $otherforum->id,
            'userid' => $f->student->id,
            'message' => 'Other forum post',
        ]);

        $entries = utils::get_user_main_posts((int) $f->forum->id, (int) $f->student->id, time() + 10);

        $this->assertNotContains('Other forum post', array_column($entries, 'message'));
    }

    /**
     * In separate groups mode only discussions of the user's groups are included.
     */
    public function test_respects_separate_groups(): void {
        $this->resetAfterTest();
        $f = $this->create_fixture(['groupmode' => SEPARATEGROUPS]);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $f->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $f->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $f->student->id]);
        $this->add_discussion($f, $f->student, 'Group A post', (int) $groupa->id);
        $this->add_discussion($f, $f->student, 'Group B post', (int) $groupb->id);

        $entries = utils::get_user_main_posts((int) $f->forum->id, (int) $f->student->id, time() + 10);

        $messages = array_column($entries, 'message');
        $this->assertContains('Group A post', $messages);
        $this->assertNotContains('Group B post', $messages);
    }

    /**
     * Names inside the texts are replaced and the author travels as a label.
     */
    public function test_pseudonymises_author_and_text(): void {
        $this->resetAfterTest();
        $f = $this->create_fixture();
        $this->add_discussion($f, $f->student, 'Mail me at me@example.com please');
        $pseudonymizer = new payload_pseudonymizer();
        $pseudonymizer->set_student((int) $f->student->id);

        $entries = utils::get_user_main_posts(
            (int) $f->forum->id,
            (int) $f->student->id,
            time() + 10,
            $pseudonymizer,
            (int) $f->discussion->id,
        );

        $this->assertSame('[STUDENT_NAME]', $entries[0]['author']);
        $this->assertSame('Mail me at [EMAIL] please', $entries[0]['message']);
    }

    /**
     * Creates a course, a forum with one student discussion and a teacher.
     *
     * @param array $forumoptions Extra forum options.
     * @return stdClass Holder (course, forum, discussion, student, teacher).
     */
    private function create_fixture(array $forumoptions = []): stdClass {
        global $DB;

        $generator = $this->getDataGenerator();
        $f = new stdClass();
        $f->course = $generator->create_course();
        $f->student = $generator->create_and_enrol($f->course, 'student');
        $f->teacher = $generator->create_and_enrol($f->course, 'editingteacher');
        $f->forum = $generator->create_module('forum', array_merge(['course' => $f->course->id], $forumoptions));
        $discussion = $generator->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $f->course->id,
            'forum' => $f->forum->id,
            'userid' => $f->student->id,
            'message' => 'Opening topic message',
        ]);
        $f->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        return $f;
    }

    /**
     * Adds a discussion to the fixture forum.
     *
     * @param stdClass $f Fixture.
     * @param stdClass $author Author.
     * @param string $message Opening message.
     * @param int $groupid Discussion group.
     * @return stdClass Discussion record.
     */
    private function add_discussion(stdClass $f, stdClass $author, string $message, int $groupid = -1): stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $f->course->id,
            'forum' => $f->forum->id,
            'userid' => $author->id,
            'message' => $message,
            'groupid' => $groupid,
        ]);
    }
}
