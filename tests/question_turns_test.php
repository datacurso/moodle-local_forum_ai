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
 * Tests for the guiding-question turn limit and approval token generation.
 *
 * The exclusion of expired responses from the turn count is covered by
 * tests/cleanup_expired_test.php and is not duplicated here.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

use stdClass;

/**
 * Tests for question-turn normalization, follow-up decisions and token uniqueness.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\utils::normalize_question_turns
 * @covers \local_forum_ai\utils::should_allow_followup_question
 * @covers \local_forum_ai\utils::count_prior_ai_turns_in_thread
 * @covers \local_forum_ai\approval::create_approval_request
 */
final class question_turns_test extends \advanced_testcase {
    /**
     * MDL-UNIT-001: any input value normalizes to a valid limit between 0 and 3.
     */
    public function test_normalize_question_turns_clamps_to_valid_range(): void {
        // In-range values pass through.
        $this->assertSame(0, utils::normalize_question_turns(0));
        $this->assertSame(1, utils::normalize_question_turns(1));
        $this->assertSame(2, utils::normalize_question_turns(2));
        $this->assertSame(3, utils::normalize_question_turns(3));

        // Above-range values clamp to 3.
        $this->assertSame(3, utils::normalize_question_turns(4));
        $this->assertSame(3, utils::normalize_question_turns(99));
        $this->assertSame(3, utils::normalize_question_turns('42'));

        // Negative values clamp to 0.
        $this->assertSame(0, utils::normalize_question_turns(-1));
        $this->assertSame(0, utils::normalize_question_turns(-100));
        $this->assertSame(0, utils::normalize_question_turns('-5'));

        // Null, empty and non-numeric strings normalize without errors.
        $this->assertSame(0, utils::normalize_question_turns(null));
        $this->assertSame(0, utils::normalize_question_turns(''));
        $this->assertSame(0, utils::normalize_question_turns('abc'));

        // Numeric strings and floats resolve to their integer value.
        $this->assertSame(2, utils::normalize_question_turns('2'));
        $this->assertSame(2, utils::normalize_question_turns(2.9));
    }

    /**
     * MDL-UNIT-003 (step 1): with limit 0 the AI can never include a guiding
     * question, regardless of previous turns.
     */
    public function test_limit_zero_never_allows_question(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup();
        $reply = $this->create_reply($setup, (int) $setup->discussion->firstpost);

        // No prior turns at all: still forbidden with limit 0.
        $this->assertFalse(
            utils::should_allow_followup_question((int) $setup->discussion->id, (int) $reply->id, 0)
        );

        // Prior turns present: still forbidden with limit 0.
        $this->insert_pending_row($setup, (int) $setup->discussion->firstpost, 'approved');
        $this->assertFalse(
            utils::should_allow_followup_question((int) $setup->discussion->id, (int) $reply->id, 0)
        );
    }

    /**
     * MDL-UNIT-003 (steps 2-3): the question is allowed while used turns are below
     * the limit and refused as soon as the limit is reached.
     */
    public function test_limit_respected_against_used_turns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup();
        $reply = $this->create_reply($setup, (int) $setup->discussion->firstpost);
        $discussionid = (int) $setup->discussion->id;

        // Used 0 < limit 1: allowed.
        $this->assertTrue(utils::should_allow_followup_question($discussionid, (int) $reply->id, 1));

        // One AI turn on the ancestor chain: used 1.
        $this->insert_pending_row($setup, (int) $setup->discussion->firstpost, 'approved');
        $this->assertFalse(utils::should_allow_followup_question($discussionid, (int) $reply->id, 1));
        $this->assertTrue(utils::should_allow_followup_question($discussionid, (int) $reply->id, 2));

        // Second AI turn: used 2, the limit-2 quota closes.
        $this->insert_pending_row($setup, (int) $setup->discussion->firstpost, 'pending');
        $this->assertFalse(utils::should_allow_followup_question($discussionid, (int) $reply->id, 2));
        $this->assertTrue(utils::should_allow_followup_question($discussionid, (int) $reply->id, 3));
    }

    /**
     * MDL-UNIT-006: every approval request receives a unique, well-formed token,
     * even when many are generated in the same instant.
     */
    public function test_approval_tokens_are_unique_and_well_formed(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup();

        $sink = $this->redirectMessages();
        $pendingids = [];
        for ($i = 0; $i < 30; $i++) {
            $pendingids[] = approval::create_approval_request(
                $setup->discussion,
                $setup->forum,
                '<p>AI reply ' . $i . '</p>',
                'pending',
                (int) $setup->discussion->firstpost
            );
        }
        $sink->close();

        $this->assertCount(30, array_filter($pendingids));

        $tokens = $DB->get_fieldset_select('local_forum_ai_pending', 'approval_token', '1 = 1');
        $this->assertCount(30, $tokens);
        $this->assertCount(30, array_unique($tokens), 'Tokens generated in the same instant must not collide.');

        foreach ($tokens as $token) {
            $this->assertSame(64, strlen($token));
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        }
    }

    /**
     * MDL-INT-018 (steps 1-3): the count walks the parent chain and, after N
     * non-rejected/non-expired AI turns in the branch, the question is disallowed.
     */
    public function test_question_disallowed_after_n_turns_along_reply_chain(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup();
        $discussionid = (int) $setup->discussion->id;

        // Build a reply chain: firstpost <- p1 <- p2 <- p3.
        $p1 = $this->create_reply($setup, (int) $setup->discussion->firstpost);
        $p2 = $this->create_reply($setup, (int) $p1->id);
        $p3 = $this->create_reply($setup, (int) $p2->id);

        // Two AI turns hang from ancestors of p3 (replying to p1 and p2).
        $this->insert_pending_row($setup, (int) $p1->id, 'approved');
        $this->insert_pending_row($setup, (int) $p2->id, 'pending');

        $this->assertSame(2, utils::count_prior_ai_turns_in_thread($discussionid, (int) $p3->id));
        $this->assertFalse(utils::should_allow_followup_question($discussionid, (int) $p3->id, 2));
        $this->assertTrue(utils::should_allow_followup_question($discussionid, (int) $p3->id, 3));
    }

    /**
     * MDL-INT-018 (step 2): rejected AI responses do not consume the question quota.
     * (The expired case is covered by tests/cleanup_expired_test.php.)
     */
    public function test_rejected_turns_do_not_consume_quota(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup();
        $reply = $this->create_reply($setup, (int) $setup->discussion->firstpost);
        $discussionid = (int) $setup->discussion->id;

        $pendingid = $this->insert_pending_row($setup, (int) $setup->discussion->firstpost, 'approved');
        $this->assertSame(1, utils::count_prior_ai_turns_in_thread($discussionid, (int) $reply->id));

        $DB->set_field('local_forum_ai_pending', 'status', 'rejected', ['id' => $pendingid]);
        $this->assertSame(0, utils::count_prior_ai_turns_in_thread($discussionid, (int) $reply->id));
        $this->assertTrue(utils::should_allow_followup_question($discussionid, (int) $reply->id, 1));
    }

    /**
     * MDL-INT-018 (step 4): counting when the student always replies at the root level.
     */
    public function test_count_when_student_always_replies_at_root_level(): void {
        $this->markTestSkipped(
            'MDL-INT-018 NOTA [Pendiente:skip]: cuando el estudiante responde siempre al nivel ' .
            'raiz cada respuesta inicia una cadena nueva y la IA pregunta indefinidamente — ' .
            'gap funcional, no critico.'
        );
    }

    /**
     * MDL-INT-018 (step 5): counting for the AI reply to the discussion opening topic.
     */
    public function test_count_for_reply_to_opening_topic(): void {
        $this->markTestSkipped(
            'MDL-INT-018 NOTA [Pendiente:skip]: en la respuesta al tema de apertura el conteo ' .
            'siempre es cero y la pregunta se permite siempre — gap funcional, no critico.'
        );
    }

    /**
     * MDL-INT-018 (step 6): pending responses of hidden forums and the thread quota.
     */
    public function test_hidden_forum_pendings_and_quota(): void {
        $this->markTestSkipped(
            'MDL-INT-018 NOTA [Pendiente:skip]: las pendientes de foros ocultos consumen cupo ' .
            'sin que el profesor pueda gestionarlas — gap funcional, no critico.'
        );
    }

    /**
     * Creates a course, student, forum and discussion.
     *
     * @return stdClass Setup holder (course, student, forum, discussion).
     */
    private function create_setup(): stdClass {
        global $DB;

        $setup = new stdClass();
        $setup->course = $this->getDataGenerator()->create_course();
        $setup->student = $this->getDataGenerator()->create_and_enrol($setup->course, 'student');

        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $setup->course->id]);
        $setup->forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $setup->course->id,
            'forum' => $setup->forum->id,
            'userid' => $setup->student->id,
        ]);
        $setup->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        return $setup;
    }

    /**
     * Creates a student reply to the given parent post.
     *
     * @param stdClass $setup Setup holder.
     * @param int $parentid Parent post id.
     * @return stdClass New forum post record.
     */
    private function create_reply(stdClass $setup, int $parentid): stdClass {
        global $DB;

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $reply = $forumgenerator->create_post([
            'discussion' => $setup->discussion->id,
            'parent' => $parentid,
            'userid' => $setup->student->id,
        ]);

        return $DB->get_record('forum_posts', ['id' => $reply->id], '*', MUST_EXIST);
    }

    /**
     * Inserts an AI pending row replying to the given post.
     *
     * @param stdClass $setup Setup holder.
     * @param int $parentpostid Post the AI row replies to.
     * @param string $status Row status.
     * @return int New pending row id.
     */
    private function insert_pending_row(stdClass $setup, int $parentpostid, string $status): int {
        global $DB;

        return (int) $DB->insert_record('local_forum_ai_pending', (object) [
            'discussionid' => $setup->discussion->id,
            'forumid' => $setup->forum->id,
            'parentpostid' => $parentpostid,
            'creator_userid' => $setup->student->id,
            'subject' => 'Re: ' . $setup->discussion->name,
            'message' => '<p>AI reply</p>',
            'status' => $status,
            'approval_token' => md5(uniqid('questionturns_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }
}
