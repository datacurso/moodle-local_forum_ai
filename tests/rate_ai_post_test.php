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
 * Tests for the standard-mechanism AI rating helper of local_forum_ai.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

use context_module;
use stdClass;

global $CFG;

require_once($CFG->dirroot . '/rating/lib.php');

/**
 * Tests that AI ratings go through rating_manager with core's forum validations.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\approval
 */
final class rate_ai_post_test extends \advanced_testcase {
    /**
     * The happy path rates as the grader through the standard API.
     */
    public function test_rates_as_grader_through_standard_api(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $adminid = (int) $USER->id;

        $fixture = $this->create_fixture(['assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 100]);
        $context = context_module::instance($fixture->cm->id);

        $sink = $this->redirectEvents();
        $result = approval::rate_ai_post(
            $fixture->cm,
            $context,
            $fixture->forum,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->student->id,
            85,
            (int) $fixture->grader->id
        );
        $events = $sink->get_events();
        $sink->close();

        $this->assertTrue($result);

        // The rating row belongs to the grader.
        $rating = $DB->get_record('rating', ['itemid' => $fixture->discussion->firstpost], '*', MUST_EXIST);
        $this->assertEquals($fixture->grader->id, $rating->userid);
        $this->assertEquals(85, $rating->rating);

        // The gradebook push logs user_graded attributed to the grader.
        $graded = array_filter($events, function ($event) {
            return $event instanceof \core\event\user_graded;
        });
        $this->assertNotEmpty($graded);
        $gradedevent = reset($graded);
        $this->assertEquals($fixture->grader->id, $gradedevent->userid);
        $this->assertEquals($fixture->student->id, $gradedevent->relateduserid);

        // The grade reaches the gradebook for the rated student.
        $gradeitem = $DB->get_record('grade_items', [
            'itemtype' => 'mod',
            'itemmodule' => 'forum',
            'iteminstance' => $fixture->forum->id,
        ], '*', MUST_EXIST);
        $grade = $DB->get_record('grade_grades', [
            'itemid' => $gradeitem->id,
            'userid' => $fixture->student->id,
        ], '*', MUST_EXIST);
        $this->assertEquals(85.0, (float) $grade->finalgrade);

        // The original user is restored after the internal switch.
        $this->assertEquals($adminid, (int) $USER->id);
    }

    /**
     * Ratings outside the forum assessment window are rejected by core validation.
     */
    public function test_rating_outside_assess_window_fails(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // The window closed yesterday; the post is created now, outside of it.
        // The ratingtime flag is required or forum_add_instance() zeroes the dates.
        $fixture = $this->create_fixture([
            'assessed' => RATING_AGGREGATE_AVERAGE,
            'scale' => 100,
            'ratingtime' => 1,
            'assesstimestart' => time() - (2 * DAYSECS),
            'assesstimefinish' => time() - DAYSECS,
        ]);
        $context = context_module::instance($fixture->cm->id);

        $result = approval::rate_ai_post(
            $fixture->cm,
            $context,
            $fixture->forum,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->student->id,
            85,
            (int) $fixture->grader->id
        );
        $this->assertDebuggingCalled();

        $this->assertFalse($result);
        $this->assertSame(0, $DB->count_records('rating'));
    }

    /**
     * Separate-groups forums reject graders outside the discussion group.
     */
    public function test_rating_respects_separate_groups(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture(
            ['assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 100, 'groupmode' => SEPARATEGROUPS],
            true
        );
        $context = context_module::instance($fixture->cm->id);

        // The grader must not bypass groups: prohibit accessallgroups on the role.
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability(
            'moodle/site:accessallgroups',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($fixture->course->id)->id,
            true
        );

        // Not a group member: rejected.
        $result = approval::rate_ai_post(
            $fixture->cm,
            $context,
            $fixture->forum,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->student->id,
            70,
            (int) $fixture->grader->id
        );
        $this->assertDebuggingCalled();
        $this->assertFalse($result);
        $this->assertSame(0, $DB->count_records('rating'));

        // Group member: accepted.
        groups_add_member($fixture->group, $fixture->grader);
        $result = approval::rate_ai_post(
            $fixture->cm,
            $context,
            $fixture->forum,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->student->id,
            70,
            (int) $fixture->grader->id
        );
        $this->assertTrue($result);
        $this->assertSame(1, $DB->count_records('rating'));
    }

    /**
     * Self-rating remains forbidden.
     */
    public function test_self_rating_fails(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture(['assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 100]);
        $context = context_module::instance($fixture->cm->id);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $graderpost = $forumgenerator->create_post([
            'discussion' => $fixture->discussion->id,
            'parent' => $fixture->discussion->firstpost,
            'userid' => $fixture->grader->id,
        ]);

        $result = approval::rate_ai_post(
            $fixture->cm,
            $context,
            $fixture->forum,
            (int) $graderpost->id,
            (int) $fixture->grader->id,
            80,
            (int) $fixture->grader->id
        );
        $this->assertDebuggingCalled();

        $this->assertFalse($result);
        $this->assertSame(0, $DB->count_records('rating'));
    }

    /**
     * Grades above the scale maximum remain rejected.
     */
    public function test_rating_above_scale_maximum_fails(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture(['assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 100]);
        $context = context_module::instance($fixture->cm->id);

        $result = approval::rate_ai_post(
            $fixture->cm,
            $context,
            $fixture->forum,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->student->id,
            150,
            (int) $fixture->grader->id
        );
        $this->assertDebuggingCalled();

        $this->assertFalse($result);
        $this->assertSame(0, $DB->count_records('rating'));
    }

    /**
     * Custom (negative id) scales now work through the standard mechanism.
     */
    public function test_custom_scale_rating_works(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Poor,Average,Good']);
        $fixture = $this->create_fixture(['assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => -$scale->id]);
        $context = context_module::instance($fixture->cm->id);

        $result = approval::rate_ai_post(
            $fixture->cm,
            $context,
            $fixture->forum,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->student->id,
            2,
            (int) $fixture->grader->id
        );

        $this->assertTrue($result);
        $this->assertSame(1, $DB->count_records('rating', ['userid' => $fixture->grader->id]));
    }

    /**
     * A suspended rater yields a clean false, never a throw.
     */
    public function test_inactive_rater_fails(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture(['assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 100]);
        $context = context_module::instance($fixture->cm->id);

        $DB->set_field('user', 'suspended', 1, ['id' => $fixture->grader->id]);

        $result = approval::rate_ai_post(
            $fixture->cm,
            $context,
            $fixture->forum,
            (int) $fixture->discussion->firstpost,
            (int) $fixture->student->id,
            85,
            (int) $fixture->grader->id
        );
        $this->assertDebuggingCalled();

        $this->assertFalse($result);
        $this->assertSame(0, $DB->count_records('rating'));
    }

    /**
     * Manual approval rates the parent post, including a grade of zero.
     *
     * @covers \local_forum_ai\external\approve_response
     */
    public function test_manual_approve_rates_including_grade_zero(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture(['assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 100]);
        $pending = $this->create_pending_row($fixture, 0);

        $this->setUser($fixture->grader);

        $result = external\approve_response::execute($pending->approval_token, 'approve');
        $this->assertTrue($result['success']);

        // The zero grade is a valid rating and must not be dropped.
        $rating = $DB->get_record('rating', ['itemid' => $pending->parentpostid], '*', MUST_EXIST);
        $this->assertEquals($fixture->grader->id, $rating->userid);
        $this->assertEquals(0, $rating->rating);
    }

    /**
     * Publication is never blocked by a failed rating (best-effort contract).
     *
     * @covers \local_forum_ai\external\approve_response
     */
    public function test_publication_succeeds_when_rating_fails(): void {
        global $DB;

        $this->resetAfterTest();

        // Assessment window already closed: any rating attempt fails.
        // The ratingtime flag is required or forum_add_instance() zeroes the dates.
        $fixture = $this->create_fixture([
            'assessed' => RATING_AGGREGATE_AVERAGE,
            'scale' => 100,
            'ratingtime' => 1,
            'assesstimestart' => time() - (2 * DAYSECS),
            'assesstimefinish' => time() - DAYSECS,
        ]);
        $pending = $this->create_pending_row($fixture, 90);

        $this->setUser($fixture->grader);
        $postcount = $DB->count_records('forum_posts');

        $result = external\approve_response::execute($pending->approval_token, 'approve');
        // Two notices: the helper's rejection and approve_response's skip message.
        $this->assertDebuggingCalledCount(2);

        $this->assertTrue($result['success']);
        $this->assertSame($postcount + 1, $DB->count_records('forum_posts'));
        $this->assertSame(0, $DB->count_records('rating'));
        $this->assertSame(
            'approved',
            $DB->get_field('local_forum_ai_pending', 'status', ['id' => $pending->id], MUST_EXIST)
        );
    }

    /**
     * Creates the shared course/forum/discussion fixture with an enabled config row.
     *
     * @param array $forumoptions Extra forum options.
     * @param bool $withgroup Whether to create a group (with the student) for the discussion.
     * @return stdClass Fixture holder (course, student, grader, forum, cm, discussion[, group]).
     */
    private function create_fixture(array $forumoptions = [], bool $withgroup = false): stdClass {
        global $DB;

        $fixture = new stdClass();
        $fixture->course = $this->getDataGenerator()->create_course();
        $fixture->student = $this->getDataGenerator()->create_and_enrol($fixture->course, 'student');
        $fixture->grader = $this->getDataGenerator()->create_and_enrol($fixture->course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module(
            'forum',
            array_merge(['course' => $fixture->course->id], $forumoptions)
        );
        $fixture->cm = get_coursemodule_from_instance('forum', $forummodule->id, $fixture->course->id, false, MUST_EXIST);
        $fixture->forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $discussionoptions = [
            'course' => $fixture->course->id,
            'forum' => $fixture->forum->id,
            'userid' => $fixture->student->id,
        ];

        if ($withgroup) {
            $fixture->group = $this->getDataGenerator()->create_group(['courseid' => $fixture->course->id]);
            groups_add_member($fixture->group, $fixture->student);
            $discussionoptions['groupid'] = $fixture->group->id;
        }

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion($discussionoptions);
        $fixture->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $configrow = $DB->get_record('local_forum_ai_config', ['forumid' => $fixture->forum->id]) ?: new stdClass();
        $configrow->forumid = $fixture->forum->id;
        $configrow->enabled = 1;
        $configrow->require_approval = 1;
        $configrow->graderid = $fixture->grader->id;
        $configrow->reply_message = 'Test prompt';
        $configrow->timemodified = time();

        if (empty($configrow->id)) {
            $configrow->timecreated = time();
            $DB->insert_record('local_forum_ai_config', $configrow);
        } else {
            $DB->update_record('local_forum_ai_config', $configrow);
        }

        return $fixture;
    }

    /**
     * Inserts a pending row awaiting manual approval, replying to the first post.
     *
     * @param stdClass $fixture Fixture holder.
     * @param int|null $grade Grade proposed by the AI.
     * @return stdClass The pending row.
     */
    private function create_pending_row(stdClass $fixture, ?int $grade): stdClass {
        global $DB;

        $pending = new stdClass();
        $pending->discussionid = $fixture->discussion->id;
        $pending->forumid = $fixture->forum->id;
        $pending->parentpostid = $fixture->discussion->firstpost;
        $pending->creator_userid = $fixture->student->id;
        $pending->subject = 'Re: ' . $fixture->discussion->name;
        $pending->message = '<p>AI reply awaiting approval</p>';
        $pending->grade = $grade;
        $pending->status = 'pending';
        $pending->approval_token = md5(uniqid('rating_', true));
        $pending->timecreated = time();
        $pending->timemodified = time();
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);

        return $pending;
    }
}
