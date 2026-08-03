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
 * Tests for the utils class of local_forum_ai.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/rating/lib.php');

/**
 * Tests for \local_forum_ai\utils.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\utils
 */
final class utils_test extends \advanced_testcase {
    /**
     * A positive scale value (point grading) must be returned as the numeric maximum.
     */
    public function test_get_scale_payload_positive_returns_int_max(): void {
        $this->assertSame(100, utils::get_scale_payload(100));
    }

    /**
     * A negative scale id pointing to an existing scale must return its option list.
     */
    public function test_get_scale_payload_named_scale_returns_options(): void {
        $this->resetAfterTest();

        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Poor, Good, Excellent']);

        $this->assertSame(
            ['Poor', 'Good', 'Excellent'],
            utils::get_scale_payload(-$scale->id)
        );
    }

    /**
     * Options must be trimmed even when the scale string contains extra spaces.
     */
    public function test_get_scale_payload_named_scale_trims_options(): void {
        $this->resetAfterTest();

        $scale = $this->getDataGenerator()->create_scale(['scale' => ' Bad ,  Average ,   Great ']);

        $this->assertSame(
            ['Bad', 'Average', 'Great'],
            utils::get_scale_payload(-$scale->id)
        );
    }

    /**
     * A negative id with no matching scale record must return null.
     */
    public function test_get_scale_payload_missing_scale_returns_null(): void {
        $this->resetAfterTest();

        $this->assertNull(utils::get_scale_payload(-999999));
    }

    /**
     * A zero scale (grading disabled) must return null.
     */
    public function test_get_scale_payload_zero_returns_null(): void {
        $this->assertNull(utils::get_scale_payload(0));
    }

    /**
     * Creates a course with a forum, an enrolled student and one post.
     *
     * The forum ratings scale (Ratings section) is set to a numeric 50 on
     * purpose, different from every whole-forum grading value used in the
     * tests, to prove the payload reads grade_forum and not scale.
     *
     * @param int $gradeforum Value for the forum 'grade_forum' field.
     * @return array Keys: cm, student.
     */
    private function setup_whole_forum_grading(int $gradeforum): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $forum = $generator->create_module('forum', [
            'course' => $course->id,
            'assessed' => RATING_AGGREGATE_AVERAGE,
            'scale' => 50,
        ]);
        $DB->set_field('forum', 'grade_forum', $gradeforum, ['id' => $forum->id]);

        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
        ]);

        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

        return ['cm' => $cm, 'student' => $student];
    }

    /**
     * Returns the participation block of the built payload.
     *
     * @param array $data Fixture returned by setup_whole_forum_grading().
     * @return array
     */
    private function build_participation(array $data): array {
        $payload = utils::build_forum_ai_payload($data['cm']->id, (int)$data['student']->id);

        return $payload['forum_participations'][0]['participation'];
    }

    /**
     * Whole-forum grading with a named scale must send the option list.
     */
    public function test_build_forum_ai_payload_sends_named_scale_options(): void {
        $this->resetAfterTest();

        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Poor, Good, Excellent']);
        $data = $this->setup_whole_forum_grading(-$scale->id);

        $participation = $this->build_participation($data);

        $this->assertSame(['Poor', 'Good', 'Excellent'], $participation['scale']);
    }

    /**
     * Whole-forum point grading must send its own maximum, not the ratings
     * scale from the Ratings section.
     */
    public function test_build_forum_ai_payload_sends_whole_forum_maximum(): void {
        $this->resetAfterTest();

        $data = $this->setup_whole_forum_grading(100);

        $participation = $this->build_participation($data);

        $this->assertSame(100, $participation['scale']);
    }

    /**
     * Whole-forum grading disabled (type "None") must send a zero scale.
     */
    public function test_build_forum_ai_payload_without_whole_forum_grading(): void {
        $this->resetAfterTest();

        $data = $this->setup_whole_forum_grading(0);

        $participation = $this->build_participation($data);

        $this->assertSame(0, $participation['scale']);
    }
}
