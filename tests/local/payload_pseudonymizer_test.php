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

namespace local_forum_ai\local;

use local_forum_ai\utils;

/**
 * Tests for the pseudonymisation applied to the payloads sent to the AI service.
 *
 * FAI-PRIV-001-R1: participant names never leave the site; e-mail addresses are masked.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group     local_forum_ai
 * @covers    \local_forum_ai\local\payload_pseudonymizer
 */
final class payload_pseudonymizer_test extends \advanced_testcase {
    /**
     * The current author is the student marker; other authors get numbered labels in
     * order of first appearance, stable for the same user within the request.
     */
    public function test_participants_get_stable_labels(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $student = $generator->create_user(['firstname' => 'Ana', 'lastname' => 'Torres']);
        $peer = $generator->create_user(['firstname' => 'Bruno', 'lastname' => 'Díaz']);
        $teacher = $generator->create_user(['firstname' => 'Carla', 'lastname' => 'Mendoza']);

        $pseudonymizer = new payload_pseudonymizer();

        $this->assertSame('[STUDENT_NAME]', $pseudonymizer->set_student((int) $student->id));
        $this->assertSame('[PARTICIPANT_1]', $pseudonymizer->label_for((int) $peer->id));
        $this->assertSame('[PARTICIPANT_2]', $pseudonymizer->label_for((int) $teacher->id));
        $this->assertSame('[PARTICIPANT_1]', $pseudonymizer->label_for((int) $peer->id));
        $this->assertSame('[STUDENT_NAME]', $pseudonymizer->label_for((int) $student->id));
    }

    /**
     * Full names, first names and last names are replaced only as whole words, in any
     * letter case: "Ana" must not alter "Anabel" or "banana".
     */
    public function test_names_inside_free_text_are_replaced_as_whole_words(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $student = $generator->create_user(['firstname' => 'Ana', 'lastname' => 'Torres']);
        $peer = $generator->create_user(['firstname' => 'Bruno', 'lastname' => 'Díaz']);

        $pseudonymizer = new payload_pseudonymizer();
        $pseudonymizer->set_student((int) $student->id);
        $pseudonymizer->label_for((int) $peer->id);

        $text = 'Ana Torres wrote to Bruno Díaz. Ana, Torres and díaz agree; ana too. ' .
            'Anabel ate a banana with Brunos at Torresville.';

        $this->assertSame(
            '[STUDENT_NAME] wrote to [PARTICIPANT_1]. [STUDENT_NAME], [STUDENT_NAME] and [PARTICIPANT_1] agree; ' .
                '[STUDENT_NAME] too. Anabel ate a banana with Brunos at Torresville.',
            $pseudonymizer->pseudonymize_text($text)
        );
    }

    /**
     * E-mail addresses are masked and the mask is never restored.
     */
    public function test_emails_are_masked(): void {
        $this->resetAfterTest();

        $student = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Torres']);

        $pseudonymizer = new payload_pseudonymizer();
        $pseudonymizer->set_student((int) $student->id);

        $masked = $pseudonymizer->pseudonymize_text(
            'Write to ana.torres@example.com or to soporte+foro@uni.edu.co, please.'
        );

        $this->assertSame('Write to [EMAIL] or to [EMAIL], please.', $masked);
        $this->assertSame($masked, $pseudonymizer->restore_text($masked));
    }

    /**
     * The markers in the AI reply are turned back into the real display names.
     */
    public function test_reply_restores_student_name(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $student = $generator->create_user(['firstname' => 'Ana', 'lastname' => 'Torres']);
        $peer = $generator->create_user(['firstname' => 'Bruno', 'lastname' => 'Díaz']);

        $pseudonymizer = new payload_pseudonymizer();
        $pseudonymizer->set_student((int) $student->id);
        $pseudonymizer->label_for((int) $peer->id);

        $this->assertSame(
            'Hola ' . fullname($student) . ', como dijo ' . fullname($peer) . '.',
            $pseudonymizer->restore_text('Hola [STUDENT_NAME], como dijo [PARTICIPANT_1].')
        );
    }

    /**
     * The grading payload keeps rubric texts verbatim (the browser maps the result back
     * by criterion text), while the evaluated student's name in the answers is replaced.
     * Feedback restoration only touches 'reply' values, never criterion texts or keys.
     */
    public function test_rubric_text_is_not_altered(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student', ['firstname' => 'Ana', 'lastname' => 'Torres']);
        $forum = $generator->create_module('forum', ['course' => $course->id, 'grade_forum' => 10]);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);
        $generator->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
            'message' => 'I am Ana and this is my answer.',
        ]);

        $context = \context_module::instance($cm->id);
        $areaid = $DB->insert_record('grading_areas', (object) [
            'contextid' => $context->id,
            'component' => 'mod_forum',
            'areaname' => 'forum',
            'activemethod' => 'rubric',
        ]);
        $definitionid = $DB->insert_record('grading_definitions', (object) [
            'areaid' => $areaid,
            'method' => 'rubric',
            'name' => 'Rubric naming Ana',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'status' => 20,
            'timecreated' => time(),
            'usercreated' => 2,
            'timemodified' => time(),
            'usermodified' => 2,
        ]);
        $criterionid = $DB->insert_record('gradingform_rubric_criteria', (object) [
            'definitionid' => $definitionid,
            'sortorder' => 1,
            'description' => 'Ana cites Torres et al.',
            'descriptionformat' => FORMAT_HTML,
        ]);
        $DB->insert_record('gradingform_rubric_levels', (object) [
            'criterionid' => $criterionid,
            'score' => 5,
            'definition' => 'Strong argument, Ana',
            'definitionformat' => FORMAT_HTML,
        ]);

        $pseudonymizer = new payload_pseudonymizer();
        $payload = utils::build_forum_ai_payload((int) $cm->id, (int) $student->id, null, $pseudonymizer);
        $participation = $payload['forum_participations'][0]['participation'];

        $this->assertSame('Rubric naming Ana', $participation['rubric']['title']);
        $this->assertSame('Ana cites Torres et al.', $participation['rubric']['criteria'][0]['criterion']);
        $this->assertSame('Strong argument, Ana', $participation['rubric']['criteria'][0]['levels'][0]['description']);
        $this->assertSame('I am [STUDENT_NAME] and this is my answer.', $participation['discussions'][0]['answer']);

        // Rubric and guide feedback: only the 'reply' values are restored.
        $rubric = [[
            'criterion' => 'Ana cites Torres et al.',
            'levels' => [['description' => 'Strong argument, Ana', 'points' => 5]],
            'reply' => 'Well argued, [STUDENT_NAME].',
        ]];
        $guide = ['Ana cites Torres et al.' => ['grade' => 4, 'reply' => ['Good work [STUDENT_NAME]', 'Keep going']]];

        $restoredrubric = $pseudonymizer->restore_replies($rubric);
        $this->assertSame('Ana cites Torres et al.', $restoredrubric[0]['criterion']);
        $this->assertSame('Strong argument, Ana', $restoredrubric[0]['levels'][0]['description']);
        $this->assertSame('Well argued, ' . fullname($student) . '.', $restoredrubric[0]['reply']);

        $restoredguide = $pseudonymizer->restore_replies($guide);
        $this->assertSame(['Ana cites Torres et al.'], array_keys($restoredguide));
        $this->assertSame(['Good work ' . fullname($student), 'Keep going'], $restoredguide['Ana cites Torres et al.']['reply']);
    }

    /**
     * Invalid UTF-8 is repaired instead of letting the replacement fail and leak the name.
     */
    public function test_invalid_utf8_is_repaired_before_replacement(): void {
        $this->resetAfterTest();

        $student = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Torres']);

        $pseudonymizer = new payload_pseudonymizer();
        $pseudonymizer->set_student((int) $student->id);

        $result = $pseudonymizer->pseudonymize_text("Hola Ana Torres \xFF fin");

        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
        $this->assertStringNotContainsString('Ana', $result);
        $this->assertStringNotContainsString('Torres', $result);
        $this->assertStringContainsString('[STUDENT_NAME]', $result);
    }

    /**
     * An author that cannot be resolved still gets a label, restored to the localised
     * fallback label instead of a hardcoded English word or a numeric id.
     */
    public function test_unresolvable_participant_restores_to_localised_label(): void {
        $pseudonymizer = new payload_pseudonymizer();

        $this->assertSame('[PARTICIPANT_1]', $pseudonymizer->label_for(9999999));
        $this->assertSame(
            get_string('unknownparticipant', 'local_forum_ai'),
            $pseudonymizer->restore_text('[PARTICIPANT_1]')
        );
    }
}
