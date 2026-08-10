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
 * Covers: MDL-UNIT-005 — Preservacion de caracteres acentuados en el contenido enviado a la IA
 * Covers: MDL-INT-034 — Resolucion de flags globales y valores por defecto
 * Covers: MDL-INT-035 — Construccion del contexto del hilo para la IA
 * Covers: MDL-INT-036 — Payload de escala para la valoracion por publicacion
 * Covers: MDL-CTR-001 — Contrato de la solicitud de generacion de respuesta
 * Covers: MDL-CTR-002 — Contrato de la solicitud de calificacion de participacion global
 * Covers: SYS-E2E-006 — Boton Revisar con IA: evaluacion con escala con nombre (partes de normalizacion de calificacion)
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
     * The default delay helper must use the plugin default and minimum-1 clamp.
     */
    public function test_get_default_delay_minutes(): void {
        $this->resetAfterTest();

        unset_config('default_delayminutes', 'local_forum_ai');
        $this->assertSame(60, utils::get_default_delay_minutes());

        set_config('default_delayminutes', 0, 'local_forum_ai');
        $this->assertSame(1, utils::get_default_delay_minutes());

        set_config('default_delayminutes', 15, 'local_forum_ai');
        $this->assertSame(15, utils::get_default_delay_minutes());
    }

    /**
     * Point grading must preserve a valid numeric grade.
     */
    public function test_normalize_review_grade_point_scale_preserves_numeric_grade(): void {
        $this->assertSame(42.0, utils::normalize_review_grade('42', 100));
    }

    /**
     * Named scales must resolve matching labels to their canonical 1-based index.
     */
    public function test_normalize_review_grade_named_scale_resolves_label(): void {
        $this->resetAfterTest();

        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Poor, Good, Excellent']);

        $this->assertSame(2, utils::normalize_review_grade('Good', -$scale->id));
    }

    /**
     * Named scales must reject labels that do not match a configured option.
     */
    public function test_normalize_review_grade_named_scale_rejects_unknown_label(): void {
        $this->resetAfterTest();

        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Poor, Good, Excellent']);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('The AI grade could not be resolved to a valid forum grade.');

        utils::normalize_review_grade('Outstanding', -$scale->id);
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

    /**
     * The participation payload must keep accents and special characters
     * verbatim in every field (forum, discussion names and student answers).
     */
    public function test_build_forum_ai_payload_preserves_accents(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $forum = $generator->create_module('forum', [
            'course' => $course->id,
            'name' => 'Fórum de discusión',
        ]);
        $generator->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
            'name' => 'Évaluation complète',
            'message' => '<p>Opinión: ñandú, café, ação</p>',
        ]);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

        $payload = utils::build_forum_ai_payload($cm->id, (int)$student->id);
        $participation = $payload['forum_participations'][0]['participation'];

        $this->assertSame('Fórum de discusión', $participation['forum']);
        $this->assertSame('Évaluation complète', $participation['discussions'][0]['discussion']);
        $this->assertStringContainsString('Opinión: ñandú, café, ação', $participation['discussions'][0]['answer']);
    }

    /**
     * The thread context must keep accents verbatim in author names and
     * message bodies.
     */
    public function test_build_thread_context_preserves_accents(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user(['firstname' => 'José', 'lastname' => 'Peñarol']);
        $generator->enrol_user($student->id, $course->id, 'student');

        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
            'message' => '<p>Opinión inicial: café añejo</p>',
        ]);
        $reply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
        ]);

        $entries = utils::build_thread_context((int)$discussion->id, (int)$reply->id);

        $this->assertNotEmpty($entries);
        $this->assertSame(fullname($student), $entries[0]['author']);
        $this->assertStringContainsString('José', $entries[0]['author']);
        $this->assertStringContainsString('Opinión inicial: café añejo', $entries[0]['message']);
    }

    /**
     * When the context is capped, the root post (the topic) must always be
     * kept along with the most recent posts.
     */
    public function test_build_thread_context_cap_keeps_root_post(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');

        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
            'message' => 'Root topic message',
        ]);

        $lastreply = null;
        for ($i = 1; $i <= 5; $i++) {
            $lastreply = $forumgenerator->create_post([
                'discussion' => $discussion->id,
                'parent' => $discussion->firstpost,
                'userid' => $student->id,
                'message' => "Reply number {$i}",
            ]);
        }

        $entries = utils::build_thread_context((int)$discussion->id, (int)$lastreply->id, 3);

        $this->assertCount(3, $entries);
        $this->assertStringContainsString('Root topic message', $entries[0]['message']);
        $this->assertStringContainsString('Reply number 3', $entries[1]['message']);
        $this->assertStringContainsString('Reply number 4', $entries[2]['message']);
    }
}
