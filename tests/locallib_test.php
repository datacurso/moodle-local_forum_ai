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
 * Tests for locallib functions of local_forum_ai.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/forum_ai/locallib.php');

/**
 * Tests for local_forum_ai_add_rating().
 *
 * @group local_forum_ai
 * @covers ::local_forum_ai_add_rating
 */
final class locallib_test extends \advanced_testcase {
    /**
     * Creates a course with a rated forum, a teacher, a student and a student post.
     *
     * @param mixed $forumscale Value for the forum 'scale' field (negative id for named scales).
     * @return array Keys: course, teacher, student, scale, forum, cm, context, post.
     */
    private function setup_rated_forum($forumscale = null): array {
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        $scale = $generator->create_scale(['scale' => 'Poor, Good, Excellent']);
        if ($forumscale === null) {
            $forumscale = -$scale->id;
        }

        $forum = $generator->create_module('forum', [
            'course' => $course->id,
            'assessed' => RATING_AGGREGATE_AVERAGE,
            'scale' => $forumscale,
        ]);

        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
        ]);
        $post = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
        ]);

        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        return [
            'course' => $course,
            'teacher' => $teacher,
            'student' => $student,
            'scale' => $scale,
            'forum' => $forum,
            'cm' => $cm,
            'context' => $context,
            'post' => $post,
        ];
    }

    /**
     * Calls local_forum_ai_add_rating() with the fixture data and a given rating.
     *
     * @param array $data Fixture returned by setup_rated_forum().
     * @param int $rating Rating value to apply.
     * @param int|null $rateruserid Rater user id (defaults to the teacher).
     * @return \stdClass Result object.
     */
    private function add_rating(array $data, int $rating, ?int $rateruserid = null): \stdClass {
        return local_forum_ai_add_rating(
            $data['cm'],
            $data['context'],
            'mod_forum',
            'post',
            $data['post']->id,
            (int)$data['forum']->scale,
            $rating,
            $data['student']->id,
            RATING_AGGREGATE_AVERAGE,
            $rateruserid ?? $data['teacher']->id
        );
    }

    /**
     * A valid rating on a named scale must succeed and store the rating row.
     */
    public function test_add_rating_named_scale_valid_rating(): void {
        global $DB;
        $this->resetAfterTest();

        $data = $this->setup_rated_forum();

        $result = $this->add_rating($data, 2);

        $this->assertFalse(property_exists($result, 'error'));
        $this->assertTrue($result->success);

        $record = $DB->get_record('rating', [
            'contextid' => $data['context']->id,
            'component' => 'mod_forum',
            'ratingarea' => 'post',
            'itemid' => $data['post']->id,
            'userid' => $data['teacher']->id,
        ], '*', MUST_EXIST);
        $this->assertEquals(2, $record->rating);
        $this->assertEquals(-$data['scale']->id, $record->scaleid);
    }

    /**
     * A rating above the named scale option count must be rejected.
     */
    public function test_add_rating_named_scale_above_max_is_invalid(): void {
        global $DB;
        $this->resetAfterTest();

        $data = $this->setup_rated_forum();

        $result = $this->add_rating($data, 4);

        $this->assertSame('ratinginvalid', $result->error);
        $this->assertEquals(0, $DB->count_records('rating', ['contextid' => $data['context']->id]));
    }

    /**
     * Rating 0 is not a selectable option on a named scale and must be rejected.
     */
    public function test_add_rating_named_scale_zero_is_invalid(): void {
        $this->resetAfterTest();

        $data = $this->setup_rated_forum();

        $result = $this->add_rating($data, 0);

        $this->assertSame('ratinginvalid', $result->error);
    }

    /**
     * Numeric point grading must accept ratings within 0..max and reject above max.
     */
    public function test_add_rating_numeric_scale_range(): void {
        $this->resetAfterTest();

        $data = $this->setup_rated_forum(10);

        $result = $this->add_rating($data, 7);
        $this->assertFalse(property_exists($result, 'error'));
        $this->assertTrue($result->success);

        $result = $this->add_rating($data, 11);
        $this->assertSame('ratinginvalid', $result->error);
    }

    /**
     * An explicit zero is a legitimate rating on numeric point grading.
     */
    public function test_add_rating_numeric_scale_accepts_explicit_zero(): void {
        global $DB;
        $this->resetAfterTest();

        $data = $this->setup_rated_forum(10);

        $result = $this->add_rating($data, 0);

        $this->assertFalse(property_exists($result, 'error'));
        $this->assertTrue($result->success);
        $record = $DB->get_record('rating', [
            'itemid' => $data['post']->id,
            'userid' => $data['teacher']->id,
        ], '*', MUST_EXIST);
        $this->assertEquals(0, $record->rating);
    }

    /**
     * A user must not be able to rate their own post.
     */
    public function test_add_rating_self_rating_is_rejected(): void {
        $this->resetAfterTest();

        $data = $this->setup_rated_forum();

        $result = $this->add_rating($data, 2, $data['student']->id);

        $this->assertSame('norate', $result->error);
    }

    /**
     * Inserts a pending AI response row for the fixture discussion.
     *
     * @param array $data Fixture returned by setup_rated_forum().
     * @param string $status Row status (pending, approved or rejected).
     * @return int Inserted record id.
     */
    private function insert_pending(array $data, string $status = 'pending'): int {
        global $DB;

        return $DB->insert_record('local_forum_ai_pending', (object)[
            'discussionid' => $data['post']->discussion,
            'forumid' => $data['forum']->id,
            'parentpostid' => $data['post']->id,
            'creator_userid' => $data['student']->id,
            'subject' => 'Re: subject',
            'message' => 'AI reply',
            'status' => $status,
            'approval_token' => sha1('token' . $status),
            'timecreated' => time(),
        ]);
    }

    /**
     * Pending rows must carry every user name field so fullname() can be
     * called on them without triggering missing-field debugging notices.
     */
    public function test_get_pending_includes_all_name_fields(): void {
        $this->resetAfterTest();

        $data = $this->setup_rated_forum();
        $this->insert_pending($data);

        $records = local_forum_ai_get_pending($data['course']->id);

        $this->assertCount(1, $records);
        $record = reset($records);
        foreach (\core_user\fields::get_name_fields() as $namefield) {
            $this->assertTrue(
                property_exists($record, $namefield),
                "Missing user name field '{$namefield}' in pending record."
            );
        }
    }

    /**
     * The exact page logic — building the user with
     * username_load_fields_from_object() and calling fullname() on it — must
     * work on pending rows without missing-name-field debugging notices.
     */
    public function test_pending_rows_support_fullname(): void {
        $this->resetAfterTest();

        $data = $this->setup_rated_forum();
        $this->insert_pending($data);

        $records = local_forum_ai_get_pending($data['course']->id);
        $record = reset($records);

        $user = username_load_fields_from_object((object)['id' => $record->creator_userid], $record);
        $name = fullname($user);

        $this->assertDebuggingNotCalled();
        $this->assertSame(fullname($data['student']), $name);
    }

    /**
     * Same page logic must work on history rows.
     */
    public function test_history_rows_support_fullname(): void {
        $this->resetAfterTest();

        $data = $this->setup_rated_forum();
        $this->insert_pending($data, 'approved');

        $records = local_forum_ai_get_history($data['course']->id);
        $record = reset($records);

        $user = username_load_fields_from_object((object)['id' => $record->creator_userid], $record);
        $name = fullname($user);

        $this->assertDebuggingNotCalled();
        $this->assertSame(fullname($data['student']), $name);
    }

    /**
     * History rows must carry every user name field for the same reason.
     */
    public function test_get_history_includes_all_name_fields(): void {
        $this->resetAfterTest();

        $data = $this->setup_rated_forum();
        $this->insert_pending($data, 'approved');

        $records = local_forum_ai_get_history($data['course']->id);

        $this->assertCount(1, $records);
        $record = reset($records);
        foreach (\core_user\fields::get_name_fields() as $namefield) {
            $this->assertTrue(
                property_exists($record, $namefield),
                "Missing user name field '{$namefield}' in history record."
            );
        }
    }
}
