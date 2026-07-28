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
use moodle_exception;
use required_capability_exception;

global $CFG;

require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests for process_review authorization behaviour.
 *
 * All scenarios are negative paths: the exception must be thrown before
 * the AI service call, so the tests pass without any network access.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\external\process_review
 */
final class process_review_test extends externallib_advanced_testcase {
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
}
