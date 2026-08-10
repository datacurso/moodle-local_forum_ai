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
 * Tests for the AI response-shape routing of process_review.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai\external;

defined('MOODLE_INTERNAL') || die();

use externallib_advanced_testcase;
use local_forum_ai\ai_service;
use local_forum_ai\mock_ai_client;
use moodle_exception;

global $CFG;

require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once(__DIR__ . '/../fixtures/mock_ai_client.php');

/**
 * Routing of the grading-service response shapes through execute().
 *
 * The AI HTTP client is replaced with a stub through the injection seam in
 * ai_service, so the full execute() path runs without network access.
 *
 * The rubric and guide branches are exercised at the response-shape level
 * (what the service returns), which is what MDL-UNIT-011 targets; building a
 * real advanced-grading rubric/guide definition only changes the request
 * payload, not the response routing, and is covered by the payload contract
 * tests (MDL-CTR-002).
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\external\process_review
 */
final class process_review_dispatch_test extends externallib_advanced_testcase {
    /**
     * Always restore the real AI client after each test.
     */
    protected function tearDown(): void {
        ai_service::set_client_for_testing(null);
        parent::tearDown();
    }

    /**
     * MDL-UNIT-011: a response with a numeric value is routed as a simple grade.
     */
    public function test_numeric_grade_response_routes_to_simple(): void {
        $this->resetAfterTest();

        [$cm, $student] = $this->setup_review_scenario();
        $this->inject_mock_response(['grade' => 7]);

        $result = process_review::execute($cm->id, $student->id);

        $this->assertSame('simple', $result['type']);
        $data = json_decode($result['data'], true);
        $this->assertEqualsWithDelta(7.0, $data['grade'], 0.0001);
    }

    /**
     * MDL-UNIT-011: a response with a rubric structure is routed as a rubric.
     */
    public function test_rubric_response_routes_to_rubric(): void {
        $this->resetAfterTest();

        [$cm, $student] = $this->setup_review_scenario();

        $rubric = [
            'criteria' => [
                [
                    'criterion' => 'Argument quality',
                    'level' => 'Strong argument',
                    'points' => 5,
                ],
            ],
            'feedback' => 'Well argued overall.',
        ];
        $this->inject_mock_response(['rubric' => $rubric]);

        $result = process_review::execute($cm->id, $student->id);

        $this->assertSame('rubric', $result['type']);
        $this->assertSame($rubric, json_decode($result['data'], true));
    }

    /**
     * MDL-UNIT-011: a response with scored criteria (marking guide shape) is
     * routed as an evaluation guide.
     */
    public function test_scored_criteria_response_routes_to_guide(): void {
        $this->resetAfterTest();

        [$cm, $student] = $this->setup_review_scenario();

        $guideresponse = [
            'criteria' => [
                [
                    'criterion' => 'Participation',
                    'score' => 8,
                    'comment' => 'Frequent and relevant posts.',
                ],
            ],
            'feedback' => 'Good participation.',
        ];
        $this->inject_mock_response($guideresponse);

        $result = process_review::execute($cm->id, $student->id);

        $this->assertSame('guide', $result['type']);
        $this->assertSame($guideresponse, json_decode($result['data'], true));
    }

    /**
     * MDL-UNIT-011: a grade the plugin cannot resolve against the forum scale
     * raises a clear error instead of returning a wrong grade.
     */
    public function test_unresolvable_grade_yields_clear_error_not_wrong_grade(): void {
        $this->resetAfterTest();

        [$cm, $student] = $this->setup_review_scenario();
        $this->inject_mock_response(['grade' => 'not-a-grade']);

        try {
            process_review::execute($cm->id, $student->id);
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_invalidgrade', $e->errorcode);
        }
    }

    /**
     * MDL-UNIT-011: an empty/unknown response never fabricates a grade. The
     * current implementation degrades a null service response to an empty
     * guide payload; the contract this test pins is that no 'simple' grade is
     * ever invented from a response that carries none.
     */
    public function test_unknown_response_shape_never_fabricates_a_grade(): void {
        $this->resetAfterTest();

        [$cm, $student] = $this->setup_review_scenario();
        $this->inject_mock_response(null);

        $result = process_review::execute($cm->id, $student->id);

        $this->assertNotSame('simple', $result['type']);
        $this->assertStringNotContainsString('grade', (string) $result['data']);
    }

    /**
     * Creates the review scenario: forum with whole-forum point grading, one
     * student with a post, and the authorized teacher as current user.
     *
     * @return array [$cm, $student, $teacher].
     */
    private function setup_review_scenario(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forummodule->id, $course->id, false, MUST_EXIST);

        // Whole-forum point grading out of 100 (drives simple-grade normalization).
        $DB->set_field('forum', 'grade_forum', 100, ['id' => $forummodule->id]);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forummodule->id,
            'userid' => $student->id,
            'message' => 'Student participation to grade',
        ]);

        // The editingteacher archetype holds local/forum_ai:useaireview.
        $this->setUser($teacher);

        return [$cm, $student, $teacher];
    }

    /**
     * Injects a mock AI client returning the given /forum/grade response.
     *
     * @param array|null $response Canned grading response.
     * @return mock_ai_client The injected mock.
     */
    private function inject_mock_response(?array $response): mock_ai_client {
        $mock = new mock_ai_client(null);
        $mock->set_response('/forum/grade', $response);
        ai_service::set_client_for_testing($mock);

        return $mock;
    }
}
