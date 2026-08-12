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
 * Tests for the authorization checks in the process_review external function.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai\external;

defined('MOODLE_INTERNAL') || die();

use externallib_advanced_testcase;
use local_forum_ai\ai_service;
use local_forum_ai\mock_ai_client;
use moodle_exception;
use required_capability_exception;

global $CFG;

require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once(__DIR__ . '/../fixtures/mock_ai_client.php');

/**
 * Tests for process_review authorization behaviour.
 *
 * All scenarios are negative paths: the exception must be thrown before
 * the AI service call, so the tests pass without any network access.
 *
 * Covers: MDL-INT-024 — Permisos y validacion de contexto en los servicios web
 * Covers: MDL-UNIT-011 — Deteccion del metodo de calificacion en la respuesta del servicio
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\external\process_review
 */
final class process_review_test extends externallib_advanced_testcase {
    /**
     * Always restore the real AI client after each test.
     */
    protected function tearDown(): void {
        ai_service::set_client_for_testing(null);
        parent::tearDown();
    }

    /**
     * A valid integer grade must remain unchanged in the simple response.
     */
    public function test_build_simple_grade_response_preserves_integer_grade(): void {
        $result = process_review::build_simple_grade_response(['grade' => 7], 100);

        $this->assertSame('simple', $result['type']);
        $this->assertSame('{"grade":7}', $result['data']);
    }

    /**
     * An invalid grade must fail visibly instead of being returned as success.
     */
    public function test_build_simple_grade_response_rejects_invalid_grade(): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('The AI grade could not be resolved to a valid forum grade.');

        process_review::build_simple_grade_response(['grade' => 'Outstanding'], 100);
    }

    /**
     * An enrolled student without the review capability must be rejected.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();

        [$cm, $student] = $this->create_forum_course();

        $this->setUser($student);

        try {
            process_review::execute($cm->id, $student->id);
            $this->fail('Expected required_capability_exception was not thrown.');
        } catch (required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
    }

    /**
     * A user from another course must be rejected before any data is built.
     */
    public function test_execute_rejects_user_from_other_course(): void {
        $this->resetAfterTest();

        [$cm] = $this->create_forum_course();

        // User enrolled in a different course only.
        $othercourse = $this->getDataGenerator()->create_course();
        $outsider = $this->getDataGenerator()->create_and_enrol($othercourse, 'student');

        $this->setUser($outsider);

        try {
            process_review::execute($cm->id, $outsider->id);
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            // Context validation fires first: course access is denied before any other check.
            $this->assertSame('requireloginerror', $e->errorcode);
        }
    }

    /**
     * A teacher with the capability cannot review a user who is not enrolled in the course.
     */
    public function test_execute_rejects_target_user_not_enrolled(): void {
        $this->resetAfterTest();

        [$cm, $student, $teacher] = $this->create_forum_course();

        // A user with no enrolment in the forum's course.
        $stranger = $this->getDataGenerator()->create_user();

        $this->setUser($teacher);

        try {
            process_review::execute($cm->id, $stranger->id);
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_usernotincourse', $e->errorcode);
        }
    }

    /**
     * A nonexistent course module id must be rejected.
     */
    public function test_execute_rejects_nonexistent_cmid(): void {
        $this->resetAfterTest();

        [$cm, $student] = $this->create_forum_course();

        $this->setAdminUser();

        try {
            process_review::execute($cm->id + 100000, $student->id);
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            // Context instantiation fails on the missing course module, before any AI activity.
            $this->assertInstanceOf(\dml_missing_record_exception::class, $e);
            $this->assertSame('invalidcoursemodule', $e->errorcode);
        }
    }

    /**
     * A teacher holding the capability must pass every authorization check.
     *
     * ai_service has no injection seam for the HTTP client, so the happy path
     * cannot assert a full AI response without a real network call. Instead this
     * test pins that execution gets PAST the auth gate: with no license key
     * configured, the datacurso client constructor fails fast and deterministically
     * (aiprovider_datacurso throws a moodle_exception before any cURL request), so
     * any failure other than the auth errorcodes proves authorization was cleared
     * without consuming AI quota or touching the network.
     */
    public function test_execute_passes_auth_for_authorized_teacher(): void {
        $this->resetAfterTest();

        [$cm, $student, $teacher] = $this->create_forum_course();

        // Guarantee the AI client fails fast before any network activity.
        unset_config('licensekey', 'aiprovider_datacurso');

        $this->setUser($teacher);

        $thrown = null;
        try {
            process_review::execute($cm->id, $student->id);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'The AI client was expected to fail without a configured license key.');
        $this->assertNotInstanceOf(required_capability_exception::class, $thrown);

        $autherrorcodes = ['nopermissions', 'requireloginerror', 'error_usernotincourse'];
        $errorcode = ($thrown instanceof moodle_exception) ? (string)$thrown->errorcode : '';
        $this->assertNotContains($errorcode, $autherrorcodes);
    }

    /**
     * A teacher whose review capability was revoked with a prohibit override must be rejected.
     */
    public function test_execute_rejects_teacher_with_revoked_capability(): void {
        global $DB;

        $this->resetAfterTest();

        [$cm, $student, $teacher] = $this->create_forum_course();

        $context = \context_module::instance($cm->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('local/forum_ai:useaireview', CAP_PROHIBIT, $roleid, $context, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($teacher);

        try {
            process_review::execute($cm->id, $student->id);
            $this->fail('Expected required_capability_exception was not thrown.');
        } catch (required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
    }

    /**
     * A user who can approve AI responses but cannot request AI reviews must be rejected.
     *
     * The coursecreator archetype holds local/forum_ai:approveresponses but not
     * local/forum_ai:useaireview, pinning the approver/reviewer role separation.
     */
    public function test_execute_rejects_approver_without_review_capability(): void {
        $this->resetAfterTest();

        [$cm, $student] = $this->create_forum_course();

        $course = get_course($cm->course);
        $approver = $this->getDataGenerator()->create_and_enrol($course, 'coursecreator');

        $this->setUser($approver);

        try {
            process_review::execute($cm->id, $student->id);
            $this->fail('Expected required_capability_exception was not thrown.');
        } catch (required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
    }

    /**
     * In separate groups mode a non-editing teacher cannot review a user from another group.
     */
    public function test_execute_rejects_separate_groups_teacher_for_other_group_user(): void {
        $this->resetAfterTest();

        [$cm, $course, $groupa, $groupb] = $this->create_separate_groups_forum();

        // The non-editing teacher archetype holds useaireview but not accessallgroups.
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $student->id]);

        $this->setUser($teacher);

        try {
            process_review::execute($cm->id, $student->id);
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_usernotingroup', $e->errorcode);
        }
    }

    /**
     * In separate groups mode a non-editing teacher can review a user from the same group.
     */
    public function test_execute_allows_separate_groups_teacher_for_same_group_user(): void {
        $this->resetAfterTest();

        [$cm, $course, $groupa] = $this->create_separate_groups_forum();

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $student->id]);

        $this->inject_mock_client();
        $this->setUser($teacher);

        $result = process_review::execute($cm->id, $student->id);

        $this->assertSame('guide', $result['type']);
    }

    /**
     * In separate groups mode a user with accessallgroups can review a user from any group.
     */
    public function test_execute_allows_access_all_groups_teacher_across_groups(): void {
        $this->resetAfterTest();

        [$cm, $course, $groupa, $groupb] = $this->create_separate_groups_forum();

        // The editingteacher archetype holds moodle/site:accessallgroups.
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $student->id]);

        $this->inject_mock_client();
        $this->setUser($teacher);

        $result = process_review::execute($cm->id, $student->id);

        $this->assertSame('guide', $result['type']);
    }

    /**
     * Every authorized review request must be audited before data leaves the site.
     */
    public function test_execute_triggers_ai_review_requested_event(): void {
        $this->resetAfterTest();

        [$cm, $student, $teacher] = $this->create_forum_course();

        $this->inject_mock_client();
        $this->setUser($teacher);

        $sink = $this->redirectEvents();
        process_review::execute($cm->id, $student->id);
        $events = $sink->get_events();
        $sink->close();

        $reviewevents = array_values(array_filter($events, static function ($event) {
            return $event instanceof \local_forum_ai\event\ai_review_requested;
        }));

        $this->assertCount(1, $reviewevents);
        $event = $reviewevents[0];
        $this->assertSame(\context_module::instance($cm->id)->id, (int) $event->contextid);
        $this->assertSame((int) $student->id, (int) $event->relateduserid);
        $this->assertSame((int) $teacher->id, (int) $event->userid);
        $this->assertSame((int) $cm->instance, (int) $event->other['forumid']);
    }

    /**
     * Creates a course with a forum, one enrolled student and one editing teacher.
     *
     * The editingteacher archetype holds local/forum_ai:useaireview by default.
     *
     * @return array [$cm, $student, $teacher].
     */
    private function create_forum_course(): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forummodule->id, $course->id, false, MUST_EXIST);

        return [$cm, $student, $teacher];
    }

    /**
     * Creates a course with a separate-groups forum and two empty groups.
     *
     * @return array [$cm, $course, $groupa, $groupb].
     */
    private function create_separate_groups_forum(): array {
        $course = $this->getDataGenerator()->create_course();

        $forummodule = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'groupmode' => SEPARATEGROUPS,
        ]);
        $cm = get_coursemodule_from_instance('forum', $forummodule->id, $course->id, false, MUST_EXIST);

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        return [$cm, $course, $groupa, $groupb];
    }

    /**
     * Injects a mock AI client so execute() completes without network access.
     *
     * The canned response carries neither 'grade' nor 'rubric', so it is
     * deterministically routed as an evaluation guide.
     *
     * @return void
     */
    private function inject_mock_client(): void {
        ai_service::set_client_for_testing(new mock_ai_client(['feedback' => 'Mock AI feedback']));
    }
}
