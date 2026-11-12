<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_forum_ai\local;

/**
 * Unit tests of the outbound boundary towards the AI service (FAI-PRIV-001-R1).
 *
 * A deterministic pseudonymiser is bound in the DI container, so these tests do not depend on
 * the provider version; provider_pseudonymiser_test covers the production binding.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group     local_forum_ai
 * @coversDefaultClass \local_forum_ai\local\outbound_payload
 */
final class outbound_payload_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        \core\di::set(userid_pseudonymiser::class, new class implements userid_pseudonymiser {
            /**
             * Deterministic token derived only from the user id.
             *
             * @param int|string $userid User id.
             * @return string
             */
            public function pseudonymise(int|string $userid): string {
                return str_repeat('a', 24) . sprintf('%08x', (int) $userid);
            }
        });
    }

    #[\Override]
    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * The chat payload keeps the content, drops Moodle ids and unknown fields, and
     * pseudonymises the user id.
     *
     * @covers ::for_chat
     */
    public function test_for_chat_keeps_content_and_drops_identifiers(): void {
        $payload = outbound_payload::for_chat([
            'course' => 'Biology',
            'forum' => 'Cells',
            'discussion' => 'Membranes',
            'discussion_id' => 501,
            'postid' => 9001,
            'cmid' => 77,
            'post' => ['subject' => 'Re: Membranes', 'message' => 'It protects the cell', 'author' => '[STUDENT_NAME]',
                'id' => 9001, 'userid' => 42],
            'thread_history' => [
                ['id' => 8001, 'order' => 1, 'author' => '[PARTICIPANT_1]', 'message' => 'Topic', 'userid' => 43],
                ['id' => 8002, 'order' => 2, 'author' => '[PARTICIPANT_2]', 'message' => 'Answer'],
            ],
            'own_main_posts' => [['id' => 7001, 'order' => 1, 'author' => '[STUDENT_NAME]', 'message' => 'Mine']],
            'userid' => '42',
            'prompt' => 'Be kind',
            'allow_followup_question' => true,
            'grading_enabled' => false,
            'scale' => null,
        ]);

        $this->assertSame([
            'course', 'forum', 'discussion', 'post', 'thread_history', 'own_main_posts', 'userid', 'prompt',
            'allow_followup_question', 'grading_enabled', 'scale',
        ], array_keys($payload));
        $this->assertSame(
            ['subject' => 'Re: Membranes', 'message' => 'It protects the cell', 'author' => '[STUDENT_NAME]'],
            $payload['post']
        );
        $this->assertSame([
            ['id' => 1, 'order' => 1, 'author' => '[PARTICIPANT_1]', 'message' => 'Topic'],
            ['id' => 2, 'order' => 2, 'author' => '[PARTICIPANT_2]', 'message' => 'Answer'],
        ], $payload['thread_history']);
        $this->assertSame(
            [['id' => 1, 'order' => 1, 'author' => '[STUDENT_NAME]', 'message' => 'Mine']],
            $payload['own_main_posts']
        );
        $this->assertSame(str_repeat('a', 24) . '0000002a', $payload['userid']);
    }

    /**
     * The grading payload keeps the discussions, drops Moodle ids and pseudonymises the
     * evaluated student.
     *
     * @covers ::for_grade
     */
    public function test_for_grade_keeps_content_and_drops_identifiers(): void {
        $payload = outbound_payload::for_grade([
            'forum_participations' => [[
                'userid' => '42',
                'email' => 'ana@example.com',
                'participation' => [
                    'forum_id' => '31',
                    'forum' => 'Cells',
                    'cmid' => 77,
                    'scale' => 10,
                    'rubric' => null,
                    'assessment_guide' => null,
                    'discussions' => [
                        ['discussion' => 'Membranes', 'discussion_id' => 501, 'answer' => 'It protects the cell'],
                    ],
                ],
            ]],
            'courseid' => 5,
        ]);

        $this->assertSame(['forum_participations'], array_keys($payload));
        $item = $payload['forum_participations'][0];
        $this->assertSame(['userid', 'participation'], array_keys($item));
        $this->assertSame(str_repeat('a', 24) . '0000002a', $item['userid']);
        $this->assertSame([
            'forum_id' => outbound_payload::FORUM_REFERENCE,
            'forum' => 'Cells',
            'scale' => 10,
            'rubric' => null,
            'assessment_guide' => null,
            'discussions' => [['discussion' => 'Membranes', 'answer' => 'It protects the cell']],
        ], $item['participation']);
    }
}
