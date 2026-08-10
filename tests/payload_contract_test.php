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
 * Contract tests for the payloads local_forum_ai sends to the AI service.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_ai_client.php');

/**
 * Chat (reply generation) and global grading payload contracts.
 *
 * The chat payload is asserted end to end: a real process_ai_post run against
 * the injected mock client, whose request log captures the exact body sent.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\task\process_ai_post
 * @covers \local_forum_ai\utils
 * @covers \local_forum_ai\helper\rubric
 * @covers \local_forum_ai\helper\guide
 */
final class payload_contract_test extends \advanced_testcase {
    /**
     * Always restore the real AI client after each test.
     */
    protected function tearDown(): void {
        ai_service::set_client_for_testing(null);
        parent::tearDown();
    }

    /**
     * MDL-UNIT-010: the post message is flattened to trimmed plain text and
     * the reply subject is built from the discussion name with the Re: prefix.
     */
    public function test_post_message_normalized_and_subject_from_discussion_name(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $post = $this->create_reply($fixture, (int) $fixture->student->id, '<p> Hola <b>mundo</b> </p>');

        $mock = $this->inject_mock(['reply' => 'Normalized payload check']);
        $messagesink = $this->redirectMessages();
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);
        $messagesink->close();

        $body = $mock->last_request()['body'];
        $this->assertSame('Hola mundo', $body['post']['message']);

        // The stored reply subject follows "Re: <discussion name>".
        $pending = $DB->get_record('local_forum_ai_pending', ['forumid' => $fixture->forum->id], '*', MUST_EXIST);
        $this->assertSame('Re: ' . $fixture->discussion->name, $pending->subject);
    }

    /**
     * MDL-UNIT-010: an unresolvable post author degrades to an empty author
     * field instead of an error or a numeric id.
     */
    public function test_unresolvable_author_yields_empty_author_field(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $post = $this->create_reply($fixture, (int) $fixture->student->id, 'Orphaned author reply');

        // Point the post at a user id that cannot be resolved.
        $DB->set_field('forum_posts', 'userid', 9999999, ['id' => $post->id]);

        $mock = $this->inject_mock(['reply' => 'Orphan author check']);
        $messagesink = $this->redirectMessages();
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);
        $messagesink->close();

        $body = $mock->last_request()['body'];
        $this->assertSame('', $body['post']['author']);
        $this->assertStringNotContainsString('9999999', (string) $body['post']['author']);
    }

    /**
     * MDL-CTR-001: the chat payload carries the full documented contract —
     * names, ids, post block, chronological thread history, string userid,
     * prompt, follow-up flag, grading flag and numeric scale — and excludes
     * deleted and private content.
     */
    public function test_chat_payload_matches_contract(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture(['assessed' => 1, 'scale' => 100]);
        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');

        // Visible reply by the teacher.
        $teacherpost = $forumgenerator->create_post([
            'discussion' => $fixture->discussion->id,
            'parent' => $fixture->discussion->firstpost,
            'userid' => $fixture->teacher->id,
            'message' => 'Visible teacher contribution',
        ]);

        // Deleted post: must never leave the site.
        $deletedpost = $forumgenerator->create_post([
            'discussion' => $fixture->discussion->id,
            'parent' => $fixture->discussion->firstpost,
            'userid' => $fixture->student->id,
            'message' => 'Deleted secret content',
        ]);
        $DB->set_field('forum_posts', 'deleted', 1, ['id' => $deletedpost->id]);

        // Private reply: must never leave the site.
        $forumgenerator->create_post([
            'discussion' => $fixture->discussion->id,
            'parent' => $fixture->discussion->firstpost,
            'userid' => $fixture->teacher->id,
            'privatereplyto' => $fixture->student->id,
            'message' => 'Private teacher note',
        ]);

        // The triggering student reply.
        $post = $this->create_reply($fixture, (int) $fixture->student->id, 'Trigger reply');

        $mock = $this->inject_mock(['reply' => 'Contract check']);
        $messagesink = $this->redirectMessages();
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);
        $messagesink->close();

        $request = $mock->last_request();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('/forum/chat/v2', $request['path']);
        $body = $request['body'];

        // Identification block.
        $this->assertSame($fixture->course->fullname, $body['course']);
        $this->assertSame($fixture->forum->name, $body['forum']);
        $this->assertSame($fixture->discussion->name, $body['discussion']);
        $this->assertEquals($fixture->discussion->id, $body['discussion_id']);
        $this->assertEquals($post->id, $body['postid']);

        // Post block.
        $this->assertSame($post->subject, $body['post']['subject']);
        $this->assertSame('Trigger reply', $body['post']['message']);
        $this->assertSame(fullname($fixture->student), $body['post']['author']);

        // Thread history: chronological, structured, no deleted/private content.
        $this->assertIsArray($body['thread_history']);
        $this->assertNotEmpty($body['thread_history']);
        $expectedorder = 1;
        foreach ($body['thread_history'] as $entry) {
            $this->assertIsInt($entry['id']);
            $this->assertSame($expectedorder, $entry['order']);
            $this->assertIsString($entry['author']);
            $this->assertIsString($entry['message']);
            $expectedorder++;
        }
        $messages = array_column($body['thread_history'], 'message');
        $this->assertContains('Visible teacher contribution', $messages);
        $this->assertNotContains('Deleted secret content', $messages);
        $this->assertNotContains('Private teacher note', $messages);
        $ids = array_column($body['thread_history'], 'id');
        $this->assertNotContains((int) $deletedpost->id, $ids);

        // Request attribution and options.
        $this->assertSame((string) $fixture->student->id, $body['userid']);
        $this->assertSame('Test prompt', $body['prompt']);
        $this->assertIsBool($body['allow_followup_question']);
        $this->assertTrue($body['grading_enabled']);
        $this->assertSame(100, $body['scale']);
    }

    /**
     * MDL-CTR-001: with per-post rating disabled the scale is null and the
     * grading flag is false.
     */
    public function test_chat_payload_scale_null_when_grading_disabled(): void {
        $this->resetAfterTest();

        $fixture = $this->create_fixture(); // Default forum: assessed = 0.
        $post = $this->create_reply($fixture, (int) $fixture->student->id, 'No grading reply');

        $mock = $this->inject_mock(['reply' => 'No grading check']);
        $messagesink = $this->redirectMessages();
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);
        $messagesink->close();

        $body = $mock->last_request()['body'];
        $this->assertFalse($body['grading_enabled']);
        $this->assertNull($body['scale']);
    }

    /**
     * MDL-CTR-001: a named scale travels as the interpretable option list, not
     * as the negative numeric scale id.
     */
    public function test_chat_payload_named_scale_sends_option_list(): void {
        global $DB;

        $this->resetAfterTest();

        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Poor, Average, Excellent']);

        $fixture = $this->create_fixture(['assessed' => 1]);
        $DB->set_field('forum', 'scale', -$scale->id, ['id' => $fixture->forum->id]);

        $post = $this->create_reply($fixture, (int) $fixture->student->id, 'Named scale reply');

        $mock = $this->inject_mock(['reply' => 'Named scale check']);
        $messagesink = $this->redirectMessages();
        $this->run_post_task((int) $post->id, (int) $fixture->cm->id);
        $messagesink->close();

        $body = $mock->last_request()['body'];
        $this->assertTrue($body['grading_enabled']);
        $this->assertSame(['Poor', 'Average', 'Excellent'], $body['scale']);
    }

    /**
     * MDL-CTR-002: the global grading payload uses the whole-forum grading
     * setting (grade_forum), never the per-post ratings scale, and includes
     * the student's participation.
     */
    public function test_grade_payload_uses_whole_forum_scale_not_post_scale(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture(['assessed' => 1, 'scale' => 50]);
        $DB->set_field('forum', 'grade_forum', 80, ['id' => $fixture->forum->id]);

        $payload = utils::build_forum_ai_payload((int) $fixture->cm->id, (int) $fixture->student->id);

        $participation = $payload['forum_participations'][0]['participation'];
        $this->assertSame(80, $participation['scale']);
        $this->assertSame((string) $fixture->forum->id, $participation['forum_id']);
        $this->assertSame($fixture->forum->name, $participation['forum']);
        $this->assertSame((string) $fixture->student->id, $payload['forum_participations'][0]['userid']);

        // The student's discussion participation is present.
        $this->assertNotEmpty($participation['discussions']);
        $this->assertSame($fixture->discussion->name, $participation['discussions'][0]['discussion']);
    }

    /**
     * MDL-CTR-002: a named whole-forum scale is sent as the interpretable
     * option list rather than the numeric scale identifier.
     */
    public function test_grade_payload_named_scale_sends_option_list(): void {
        global $DB;

        $this->resetAfterTest();

        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Bajo, Medio, Alto']);

        $fixture = $this->create_fixture();
        $DB->set_field('forum', 'grade_forum', -$scale->id, ['id' => $fixture->forum->id]);

        $payload = utils::build_forum_ai_payload((int) $fixture->cm->id, (int) $fixture->student->id);

        $participation = $payload['forum_participations'][0]['participation'];
        $this->assertSame(['Bajo', 'Medio', 'Alto'], $participation['scale']);
    }

    /**
     * MDL-CTR-002: only the active advanced grading method definition travels
     * in the payload — rubric XOR guide, never both.
     */
    public function test_grade_payload_includes_only_active_method_definition(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_fixture();
        $DB->set_field('forum', 'grade_forum', 100, ['id' => $fixture->forum->id]);
        $context = \context_module::instance($fixture->cm->id);

        // No advanced grading configured: both containers are null.
        $payload = utils::build_forum_ai_payload((int) $fixture->cm->id, (int) $fixture->student->id);
        $participation = $payload['forum_participations'][0]['participation'];
        $this->assertNull($participation['rubric']);
        $this->assertNull($participation['assessment_guide']);

        // Configure BOTH definitions in the whole-forum grading area, rubric active.
        $areaid = $DB->insert_record('grading_areas', (object) [
            'contextid' => $context->id,
            'component' => 'mod_forum',
            'areaname' => 'forum',
            'activemethod' => 'rubric',
        ]);
        $rubricdefid = $this->insert_grading_definition($areaid, 'rubric', 'Rubric for forum');
        $criterionid = $DB->insert_record('gradingform_rubric_criteria', (object) [
            'definitionid' => $rubricdefid,
            'sortorder' => 1,
            'description' => 'Argument quality',
            'descriptionformat' => FORMAT_HTML,
        ]);
        $DB->insert_record('gradingform_rubric_levels', (object) [
            'criterionid' => $criterionid,
            'score' => 5,
            'definition' => 'Strong argument',
            'definitionformat' => FORMAT_HTML,
        ]);

        $guidedefid = $this->insert_grading_definition($areaid, 'guide', 'Guide for forum');
        $DB->insert_record('gradingform_guide_criteria', (object) [
            'definitionid' => $guidedefid,
            'sortorder' => 1,
            'shortname' => 'Participation',
            'description' => 'How much the student participates',
            'descriptionformat' => FORMAT_HTML,
            'descriptionmarkers' => 'Check frequency',
            'descriptionmarkersformat' => FORMAT_HTML,
            'maxscore' => 10,
        ]);

        // Active method rubric: rubric present, guide absent.
        $payload = utils::build_forum_ai_payload((int) $fixture->cm->id, (int) $fixture->student->id);
        $participation = $payload['forum_participations'][0]['participation'];
        $this->assertNotNull($participation['rubric']);
        $this->assertNull($participation['assessment_guide']);
        $this->assertSame('Rubric for forum', $participation['rubric']['title']);
        $this->assertSame('Argument quality', $participation['rubric']['criteria'][0]['criterion']);
        $this->assertSame(5, $participation['rubric']['criteria'][0]['levels'][0]['points']);

        // Switch the active method to guide: guide present, rubric absent.
        $DB->set_field('grading_areas', 'activemethod', 'guide', ['id' => $areaid]);
        $payload = utils::build_forum_ai_payload((int) $fixture->cm->id, (int) $fixture->student->id);
        $participation = $payload['forum_participations'][0]['participation'];
        $this->assertNull($participation['rubric']);
        $this->assertNotNull($participation['assessment_guide']);
        $this->assertSame('Guide for forum', $participation['assessment_guide']['title']);
        $this->assertSame('Participation', $participation['assessment_guide']['criteria'][0]['criterion']);
        $this->assertSame(10.0, $participation['assessment_guide']['criteria'][0]['maximum_score']);
    }

    /**
     * MDL-CTR-002: every post of the student in the same discussion must be
     * part of the participation sent for global grading.
     */
    public function test_grade_payload_includes_all_posts_per_discussion(): void {
        $this->markTestSkipped(
            'Se envia una sola publicacion por discusion cuando el estudiante tiene varias — la ' .
            'calificacion global se basa en participacion incompleta; gap funcional pendiente.'
        );
    }

    /**
     * MDL-CTR-003: the forum description and post attachments should reach the
     * AI service as pedagogical context.
     */
    public function test_forum_intro_and_attachments_sent_to_service(): void {
        $this->markTestSkipped(
            'Ni la descripcion del foro ni los adjuntos se envian al servicio de IA; el contrato del ' .
            'servicio tampoco los contempla — funcionalidad pendiente en plugin y servicio.'
        );
    }

    /**
     * Inserts a minimal grading definition row.
     *
     * @param int $areaid Grading area id.
     * @param string $method Grading method ('rubric' or 'guide').
     * @param string $name Definition name.
     * @return int Definition id.
     */
    private function insert_grading_definition(int $areaid, string $method, string $name): int {
        global $DB, $USER;

        $now = time();

        return (int) $DB->insert_record('grading_definitions', (object) [
            'areaid' => $areaid,
            'method' => $method,
            'name' => $name,
            'description' => 'Definition used by payload contract tests',
            'descriptionformat' => FORMAT_HTML,
            'status' => 20, // Ready for use.
            'timecreated' => $now,
            'usercreated' => $USER->id ?: 2,
            'timemodified' => $now,
            'usermodified' => $USER->id ?: 2,
        ]);
    }

    /**
     * Creates course, users, forum, discussion and an enabled config row.
     *
     * @param array $forumoptions Extra forum options (e.g. assessed, scale).
     * @return stdClass Fixture holder (course, student, teacher, forum, cm, discussion).
     */
    private function create_fixture(array $forumoptions = []): stdClass {
        global $DB;

        $fixture = new stdClass();
        $fixture->course = $this->getDataGenerator()->create_course();
        $fixture->student = $this->getDataGenerator()->create_and_enrol($fixture->course, 'student');
        $fixture->teacher = $this->getDataGenerator()->create_and_enrol($fixture->course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module(
            'forum',
            array_merge(['course' => $fixture->course->id], $forumoptions)
        );
        $fixture->cm = get_coursemodule_from_instance('forum', $forummodule->id, $fixture->course->id, false, MUST_EXIST);
        $fixture->forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $fixture->course->id,
            'forum' => $fixture->forum->id,
            'userid' => $fixture->student->id,
            'message' => 'Opening topic message',
        ]);
        $fixture->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $configrow = $DB->get_record('local_forum_ai_config', ['forumid' => $fixture->forum->id]) ?: new stdClass();
        $configrow->forumid = $fixture->forum->id;
        $configrow->enabled = 1;
        $configrow->require_approval = 1;
        $configrow->allowedroles = '';
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
     * Creates a reply post in the fixture discussion.
     *
     * @param stdClass $fixture Fixture holder.
     * @param int $userid Post author.
     * @param string $message Post message.
     * @return stdClass The new post record.
     */
    private function create_reply(stdClass $fixture, int $userid, string $message): stdClass {
        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');

        return $forumgenerator->create_post([
            'discussion' => $fixture->discussion->id,
            'parent' => $fixture->discussion->firstpost,
            'userid' => $userid,
            'message' => $message,
        ]);
    }

    /**
     * Runs the process_ai_post adhoc task for a post, capturing mtrace output.
     *
     * @param int $postid Post id.
     * @param int $cmid Course module id.
     * @return string Captured output.
     */
    private function run_post_task(int $postid, int $cmid): string {
        $task = new task\process_ai_post();
        $task->set_custom_data((object) ['postid' => $postid, 'cmid' => $cmid]);

        ob_start();
        try {
            $task->execute();
        } finally {
            $output = ob_get_clean();
        }

        return (string) $output;
    }

    /**
     * Injects a fresh mock AI client through the test seam.
     *
     * @param array|null $defaultresponse Default canned response.
     * @return mock_ai_client The injected mock.
     */
    private function inject_mock(?array $defaultresponse = ['reply' => 'Mock AI reply']): mock_ai_client {
        $mock = new mock_ai_client($defaultresponse);
        ai_service::set_client_for_testing($mock);

        return $mock;
    }
}
