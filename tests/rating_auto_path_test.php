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
 * Tests for the automatic-path rating writer local_forum_ai_add_rating().
 *
 * The automatic/delayed pipeline rates through the plugin's own writer
 * (classes/task/process_ai_post.php:179) while a second, standard mechanism
 * (approval::rate_ai_post, line 234) also runs in the same execution. These
 * tests assert the behavior specified for MDL-INT-016: the automatic path
 * must apply the same standard Moodle validations and traceability as the
 * manual path. approval::rate_ai_post itself is covered by
 * tests/rate_ai_post_test.php; named-scale/point-scale ranges and self-rating
 * of the custom writer are covered by tests/locallib_test.php.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

use context_module;
use stdClass;

global $CFG;

require_once($CFG->dirroot . '/local/forum_ai/locallib.php');
require_once($CFG->dirroot . '/rating/lib.php');

/**
 * Tests that the automatic rating path enforces the standard rating validations.
 *
 * @group local_forum_ai
 * @covers ::local_forum_ai_add_rating
 */
final class rating_auto_path_test extends \advanced_testcase {
    /**
     * MDL-INT-016 (step 2) [Pendiente:fail]: the automatic writer must respect the
     * forum assessment date window (assesstimestart/assesstimefinish).
     *
     * Asserts the CORRECT behavior: rating outside the window must be rejected like
     * the standard mechanism does. Fails on current code because
     * local_forum_ai_add_rating() (locallib.php) never checks the window.
     */
    public function test_rating_outside_assess_window_is_rejected(): void {
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

        $result = local_forum_ai_add_rating(
            $fixture->cm,
            $context,
            'mod_forum',
            'post',
            (int) $fixture->discussion->firstpost,
            (int) $fixture->forum->scale,
            85,
            (int) $fixture->student->id,
            (int) $fixture->forum->assessed,
            (int) $fixture->grader->id
        );

        $this->assertNotEmpty($result->error ?? null, 'Rating outside the assessment window must be rejected.');
        $this->assertSame(0, $DB->count_records('rating'));
    }

    /**
     * MDL-INT-016 (steps 2 and 5) [Pendiente:fail]: in separate-groups forums the
     * automatic writer must reject a grader who is not a member of the discussion
     * group (and has no access to all groups).
     *
     * Asserts the CORRECT behavior. Fails on current code because
     * local_forum_ai_add_rating() never runs the group checks that
     * forum_rating_validate() applies in the standard mechanism.
     */
    public function test_rating_respects_separate_groups_membership(): void {
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

        $result = local_forum_ai_add_rating(
            $fixture->cm,
            $context,
            'mod_forum',
            'post',
            (int) $fixture->discussion->firstpost,
            (int) $fixture->forum->scale,
            70,
            (int) $fixture->student->id,
            (int) $fixture->forum->assessed,
            (int) $fixture->grader->id
        );

        $this->assertNotEmpty(
            $result->error ?? null,
            'A grader outside the separate group must not be able to rate the post.'
        );
        $this->assertSame(0, $DB->count_records('rating'));
    }

    /**
     * MDL-INT-016 (step 3) [Pendiente:fail]: automatic ratings must produce the same
     * grading events as the manual mechanism, attributed to the configured grader.
     *
     * Asserts the CORRECT behavior. Fails on current code because
     * local_forum_ai_add_rating() pushes the grade without switching to the grader,
     * so the user_graded event is attributed to the task user instead of the grader.
     */
    public function test_rating_fires_grading_event_attributed_to_grader(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture(['assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 100]);
        $context = context_module::instance($fixture->cm->id);

        $sink = $this->redirectEvents();
        $result = local_forum_ai_add_rating(
            $fixture->cm,
            $context,
            'mod_forum',
            'post',
            (int) $fixture->discussion->firstpost,
            (int) $fixture->forum->scale,
            85,
            (int) $fixture->student->id,
            (int) $fixture->forum->assessed,
            (int) $fixture->grader->id
        );
        $events = $sink->get_events();
        $sink->close();

        $this->assertTrue((bool) ($result->success ?? false));

        $graded = array_values(array_filter($events, static function ($event): bool {
            return $event instanceof \core\event\user_graded;
        }));
        $this->assertNotEmpty($graded, 'The automatic rating must log a user_graded event.');
        $this->assertEquals(
            (int) $fixture->grader->id,
            (int) $graded[0]->userid,
            'The grading event must be attributed to the configured grader.'
        );
        $this->assertEquals((int) $fixture->student->id, (int) $graded[0]->relateduserid);
    }

    /**
     * MDL-INT-016 (steps 1-2) [Pendiente:fail]: when both rating mechanisms of the
     * automatic pipeline run over the same post in the same execution (as
     * classes/task/process_ai_post.php:179 and :234 do), the rating must be applied
     * exactly once and attributed to the grader.
     *
     * Asserts the CORRECT behavior: a single grading application. Fails on current
     * code because both mechanisms run for the same post, duplicating the gradebook
     * push (and the first one is not attributed to the grader).
     */
    public function test_rating_applied_exactly_once_when_both_mechanisms_run(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture(['assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 100]);
        $context = context_module::instance($fixture->cm->id);

        $sink = $this->redirectEvents();

        // Mechanism 1: the plugin's own writer (task line 179).
        local_forum_ai_add_rating(
            $fixture->cm,
            $context,
            'mod_forum',
            'post',
            (int) $fixture->discussion->firstpost,
            (int) $fixture->forum->scale,
            85,
            (int) $fixture->student->id,
            (int) $fixture->forum->assessed,
            (int) $fixture->grader->id
        );

        // Mechanism 2: the standard helper (task line 234), same post, same execution.
        approval::rate_ai_post(
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

        $this->assertSame(1, $DB->count_records('rating', ['itemid' => $fixture->discussion->firstpost]));

        $graded = array_values(array_filter($events, static function ($event): bool {
            return $event instanceof \core\event\user_graded;
        }));
        $this->assertCount(1, $graded, 'The rating must be applied (and logged) exactly once per post.');
        $this->assertEquals(
            (int) $fixture->grader->id,
            (int) $graded[0]->userid,
            'The single grading application must be attributed to the grader.'
        );
    }

    /**
     * MDL-INT-016 (step 2, capability — fixed in dev): a grader without
     * moodle/rating:rate is rejected by the automatic writer.
     */
    public function test_rating_without_capability_is_rejected(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_fixture(['assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 100]);
        $context = context_module::instance($fixture->cm->id);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability(
            'moodle/rating:rate',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($fixture->course->id)->id,
            true
        );

        $result = local_forum_ai_add_rating(
            $fixture->cm,
            $context,
            'mod_forum',
            'post',
            (int) $fixture->discussion->firstpost,
            (int) $fixture->forum->scale,
            85,
            (int) $fixture->student->id,
            (int) $fixture->forum->assessed,
            (int) $fixture->grader->id
        );

        $this->assertSame('ratepermissiondenied', $result->error ?? null);
        $this->assertSame(0, $DB->count_records('rating'));
    }

    /**
     * MDL-INT-016 (step 4): the teacher receives a notice when the rating cannot
     * be applied, instead of a silent failure.
     */
    public function test_teacher_notified_when_rating_fails(): void {
        $this->markTestSkipped(
            'MDL-INT-016 NOTA [Pendiente]: los fallos de valoracion del flujo automatico no se ' .
            'notifican al profesor (solo mtrace/debugging); el aviso al profesor esta pendiente ' .
            'de implementar.'
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

        $DB->insert_record('local_forum_ai_config', (object) [
            'forumid' => $fixture->forum->id,
            'enabled' => 1,
            'require_approval' => 0,
            'graderid' => $fixture->grader->id,
            'reply_message' => 'Test prompt',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return $fixture;
    }
}
