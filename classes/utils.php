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

namespace local_forum_ai;

use local_forum_ai\helper\rubric;
use local_forum_ai\helper\guide;

/**
 * Utility functions for local_forum_ai.
 *
 * @package     local_forum_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class utils {
    /**
     * Mapping of accented and special characters to plain UTF-8 equivalents.
     *
     * @var array
     */
    private static $unwanted = [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ñ' => 'n', 'Ñ' => 'N',
    ];

    /**
     * Remove accents and special characters while keeping UTF-8.
     *
     * @param string $text Input text.
     * @return string Cleaned text.
     */
    public static function remove_accents($text) {
        return strtr($text, self::$unwanted);
    }

    /**
     * Normalize the payload by iterating over all its values.
     *
     * @param array $payload Input array payload.
     * @return array Normalized array.
     */
    public static function normalize_payload(array $payload) {
        array_walk_recursive($payload, function (&$item) {
            if (is_string($item)) {
                $item = self::remove_accents($item);
            }
        });
        return $payload;
    }

    /**
     * Checks whether forum AI feature is globally enabled.
     *
     * @return bool
     */
    public static function is_feature_enabled(): bool {
        $enabled = get_config('local_forum_ai', 'enableforumai');
        if ($enabled === false || $enabled === '') {
            return true;
        }

        return !empty($enabled);
    }

    /**
     * Checks whether forum AI can be enabled globally per forum.
     *
     * @return bool
     */
    public static function is_global_ai_enabled(): bool {
        $enabled = get_config('local_forum_ai', 'default_enabled');
        if ($enabled === false || $enabled === '') {
            return true;
        }

        return !empty($enabled);
    }

    /**
     * Disables AI in all existing forum configurations.
     *
     * @return void
     */
    public static function disable_all_forums_ai(): void {
        global $DB;

        $records = $DB->get_records('local_forum_ai_config');
        if (!$records) {
            return;
        }

        $now = time();
        foreach ($records as $record) {
            $record->enabled = 0;
            $record->timemodified = $now;
            $DB->update_record('local_forum_ai_config', $record);
        }
    }

    /**
     * Normalize the configured question-turn limit to an integer in [0, 3].
     *
     * @param mixed $value Raw configured value.
     * @return int
     */
    public static function normalize_question_turns($value): int {
        $parsed = (int)($value ?? 0);
        if ($parsed < 0) {
            return 0;
        }
        if ($parsed > 3) {
            return 3;
        }
        return $parsed;
    }

    /**
     * Gets global default for "question turns with follow-up".
     *
     * @return int
     */
    public static function get_default_question_turns(): int {
        $raw = get_config('local_forum_ai', 'default_question_turns');
        if ($raw === false || $raw === '') {
            return 1;
        }

        return self::normalize_question_turns($raw);
    }

    /**
     * Gets effective question-turn limit using forum config or global fallback.
     *
     * @param \stdClass|null $config Forum config row.
     * @return int
     */
    public static function get_effective_question_turns(?\stdClass $config): int {
        if ($config && isset($config->questionturns)) {
            return self::normalize_question_turns($config->questionturns);
        }

        return self::get_default_question_turns();
    }

    /**
     * Returns ancestor post IDs for a post within the same discussion.
     *
     * The returned list is ordered from direct parent to root post.
     *
     * @param int $discussionid Discussion ID.
     * @param int $postid Current post ID.
     * @return array<int>
     */
    public static function get_thread_ancestor_post_ids(int $discussionid, int $postid): array {
        global $DB;

        $ancestors = [];
        $visited = [];

        $currentpost = $DB->get_record(
            'forum_posts',
            ['id' => $postid, 'discussion' => $discussionid],
            'id,parent',
            IGNORE_MISSING
        );

        if (!$currentpost) {
            return [];
        }

        $parentid = (int)($currentpost->parent ?? 0);
        while ($parentid > 0 && !isset($visited[$parentid])) {
            $visited[$parentid] = true;
            $parentpost = $DB->get_record(
                'forum_posts',
                ['id' => $parentid, 'discussion' => $discussionid],
                'id,parent',
                IGNORE_MISSING
            );

            if (!$parentpost) {
                break;
            }

            $ancestors[] = (int)$parentpost->id;
            $parentid = (int)($parentpost->parent ?? 0);
        }

        return $ancestors;
    }

    /**
     * Counts previous AI responses in the same reply thread branch.
     *
     * Rejected responses are excluded from the count.
     *
     * @param int $discussionid Discussion ID.
     * @param int $postid Current post ID.
     * @return int
     */
    public static function count_prior_ai_turns_in_thread(int $discussionid, int $postid): int {
        global $DB;

        $ancestorids = self::get_thread_ancestor_post_ids($discussionid, $postid);
        if (empty($ancestorids)) {
            return 0;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($ancestorids, SQL_PARAMS_NAMED);
        $params = [
            'discussionid' => $discussionid,
            'rejected' => 'rejected',
        ] + $inparams;

        $sql = "SELECT COUNT(1)
                  FROM {local_forum_ai_pending}
                 WHERE discussionid = :discussionid
                   AND parentpostid $insql
                   AND status <> :rejected";

        return (int)$DB->count_records_sql($sql, $params);
    }

    /**
     * Determines whether AI can still end with a guiding question.
     *
     * @param int $discussionid Discussion ID.
     * @param int $postid Current post ID.
     * @param int $questionturnlimit Configured max turns with question.
     * @return bool
     */
    public static function should_allow_followup_question(
        int $discussionid,
        int $postid,
        int $questionturnlimit
    ): bool {
        if ($questionturnlimit <= 0) {
            return false;
        }

        $usedturns = self::count_prior_ai_turns_in_thread($discussionid, $postid);
        return $usedturns < $questionturnlimit;
    }

    /**
     * Builds the structured payload for the AI forum evaluation service.
     *
     * This method gathers all necessary data related to a user's participation
     * in a specific forum, including discussions, posts, grading configuration
     * and associated evaluation method (simple grade, rubric or guide).
     *
     * The return structure is designed to be directly consumed by the AI
     * service responsible for generating automatic assessments.
     *
     * @param int $cmid Course module ID of the forum.
     * @param int $userid User ID whose participation will be analyzed.
     * @return array Structured payload ready to be sent to the AI service.
     */
    public static function build_forum_ai_payload(int $cmid, int $userid): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/grade/grading/lib.php');

        $cm = get_coursemodule_from_id('forum', $cmid, 0, false, MUST_EXIST);
        $forum = $DB->get_record('forum', ['id' => $cm->instance], '*', MUST_EXIST);

        // Get active grading method from grading areas.
        $context = \context_module::instance($cmid);
        $gradingmanager = get_grading_manager($context, 'mod_forum', 'forum');
        $activemethod = $gradingmanager->get_active_method();

        // Initialize grading data containers.
        $rubricdata = null;
        $guidedata = null;

        // Only retrieve data for the currently configured grading method.
        if ($activemethod === 'rubric') {
            $rubricdata = rubric::get($cmid);
        } else if ($activemethod === 'guide') {
            $guidedata = guide::get($cmid);
        }

        $posts = $DB->get_records_sql("
            SELECT d.id, d.name, p.message
            FROM {forum_discussions} d
            JOIN {forum_posts} p ON p.discussion = d.id
            WHERE p.userid = ?
            AND d.forum = ?
        ", [$userid, $forum->id]);

        $discussions = [];

        foreach ($posts as $p) {
            $discussions[] = [
                'discussion' => $p->name,
                'discussion_id' => $p->id,
                'answer' => trim(strip_tags($p->message)),
            ];
        }

        $participation = [
            'userid' => (string)$userid,
            'participation' => [
                'forum_id' => (string)$forum->id,
                'forum' => $forum->name,
                'scale' => (string)$forum->scale,
                'rubric' => $rubricdata,
                'assessment_guide' => $guidedata,
                'discussions' => $discussions,
            ],
        ];

        return [
            'forum_participations' => array_values([$participation]),
        ];
    }
}
