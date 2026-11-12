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
 * Outbound boundary of the plugin towards the AI service (FAI-PRIV-001-R1).
 *
 * Every payload handed to the AI service goes through {@see for_chat()} or {@see for_grade()}.
 * The discussions and their content travel, because they are what the AI evaluates; nothing
 * that identifies a person or an activity does:
 *
 * - Only the fields listed in the allowlists below leave the site, at every nesting level, so
 *   the outbound contract cannot grow silently. Keep them in sync with _docs/privacy.md.
 * - Moodle ids of discussions, posts and forums are dropped. The service schema still requires
 *   an `id` per thread entry and a `forum_id` per participation, so those carry references that
 *   only mean something inside the request: the entry's position and {@see FORUM_REFERENCE}.
 * - Every user id becomes the suite's shared site-scoped pseudonym, obtained from the
 *   {@see userid_pseudonymiser} port; this plugin derives no token of its own.
 *
 * Names inside the texts are handled earlier by {@see payload_pseudonymizer}.
 *
 * @package    local_forum_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class outbound_payload {
    /** @var string[] Top-level fields of a /forum/chat/v2 request. */
    public const CHAT_FIELDS = [
        'course',
        'forum',
        'discussion',
        'post',
        'thread_history',
        'own_main_posts',
        'userid',
        'prompt',
        'allow_followup_question',
        'grading_enabled',
        'scale',
        'lang',
    ];

    /** @var string[] Fields of the post block of a chat request. */
    public const POST_FIELDS = ['subject', 'message', 'author'];

    /** @var string[] Fields of a thread_history or own_main_posts entry. */
    public const ENTRY_FIELDS = ['id', 'order', 'author', 'message'];

    /** @var string[] Top-level fields of a /forum/grade request. */
    public const GRADE_FIELDS = ['forum_participations', 'lang'];

    /** @var string[] Fields of one entry of forum_participations. */
    public const PARTICIPATION_ENTRY_FIELDS = ['userid', 'participation'];

    /** @var string[] Fields of the participation block. */
    public const PARTICIPATION_FIELDS = ['forum_id', 'forum', 'scale', 'rubric', 'assessment_guide', 'discussions'];

    /** @var string[] Fields of a graded discussion. */
    public const DISCUSSION_FIELDS = ['discussion', 'answer'];

    /** @var string Request-local forum reference sent instead of the forum id the schema requires. */
    public const FORUM_REFERENCE = '1';

    /**
     * Minimise a chat (reply generation) payload.
     *
     * @param array $payload Payload built by the tasks.
     * @return array The payload that may leave the site.
     */
    public static function for_chat(array $payload): array {
        $payload = self::only($payload, self::CHAT_FIELDS);

        if (isset($payload['post']) && is_array($payload['post'])) {
            $payload['post'] = self::only($payload['post'], self::POST_FIELDS);
        }
        foreach (['thread_history', 'own_main_posts'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $payload[$key] = self::entries($payload[$key]);
            }
        }
        if (isset($payload['userid'])) {
            $payload['userid'] = self::pseudonymise($payload['userid']);
        }

        return $payload;
    }

    /**
     * Minimise a participation grading payload.
     *
     * @param array $payload Payload built by utils::build_forum_ai_payload().
     * @return array The payload that may leave the site.
     */
    public static function for_grade(array $payload): array {
        $payload = self::only($payload, self::GRADE_FIELDS);

        $participations = [];
        foreach ((array) ($payload['forum_participations'] ?? []) as $item) {
            $item = self::only((array) $item, self::PARTICIPATION_ENTRY_FIELDS);
            if (isset($item['userid'])) {
                $item['userid'] = self::pseudonymise($item['userid']);
            }

            $participation = self::only((array) ($item['participation'] ?? []), self::PARTICIPATION_FIELDS);
            $participation['forum_id'] = self::FORUM_REFERENCE;
            $participation['discussions'] = array_map(
                fn($discussion): array => self::only((array) $discussion, self::DISCUSSION_FIELDS),
                array_values((array) ($participation['discussions'] ?? []))
            );
            $item['participation'] = $participation;

            $participations[] = $item;
        }
        $payload['forum_participations'] = $participations;

        return $payload;
    }

    /**
     * Keep the allowed thread entry fields, with the entry position as its id.
     *
     * @param array $entries Thread or own main post entries.
     * @return array
     */
    private static function entries(array $entries): array {
        $result = [];
        foreach (array_values($entries) as $index => $entry) {
            $entry = self::only((array) $entry, self::ENTRY_FIELDS);
            $position = $index + 1;
            $entry['id'] = $position;
            $entry['order'] = (int) ($entry['order'] ?? $position);
            $result[] = $entry;
        }
        return $result;
    }

    /**
     * Keep only the listed keys of an array.
     *
     * @param array $data Data to filter.
     * @param string[] $fields Allowed keys.
     * @return array
     */
    private static function only(array $data, array $fields): array {
        return array_intersect_key($data, array_flip($fields));
    }

    /**
     * Return the shared site-scoped pseudonym of a user id.
     *
     * @param mixed $userid Raw user id or an already derived token.
     * @return string
     */
    private static function pseudonymise(mixed $userid): string {
        return \core\di::get(userid_pseudonymiser::class)->pseudonymise(is_scalar($userid) ? (string) $userid : '');
    }
}
