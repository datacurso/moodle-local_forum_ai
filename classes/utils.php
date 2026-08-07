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
use core_text;

/**
 * Utility functions for local_forum_ai.
 *
 * @package     local_forum_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class utils {
    /**
     * Stored value meaning "follow the global default" for reply in locked discussions.
     */
    public const REPLY_IN_LOCKED_INHERIT = 2;

    /**
     * Build the scale value to send to the AI service for post grading.
     *
     * Point grading (positive scale) keeps the numeric maximum. Named scales
     * are stored by Moodle as the negative id of the scale record; in that
     * case the ordered list of option names is returned so the AI can pick a
     * valid option. The AI is expected to return the 1-based index of the
     * chosen option as the grade.
     *
     * @param int $scale Forum 'scale' field (max grade, or negative scale id).
     * @return int|string[]|null Numeric maximum, list of scale options, or null
     *                           when the scale is unset or cannot be resolved.
     */
    public static function get_scale_payload(int $scale) {
        global $DB;

        if ($scale > 0) {
            return $scale;
        }

        if ($scale < 0) {
            $scalerecord = $DB->get_record('scale', ['id' => -$scale]);
            if ($scalerecord) {
                return array_map('trim', explode(',', $scalerecord->scale));
            }
        }

        return null;
    }

    /**
     * Normalize an AI grade against the forum grading configuration.
     *
     * Point grades accept numeric values and are returned as floats to match
     * the current simple-grade payload. Named scales accept either the
     * 1-based option index or the option label itself and are returned as the
     * canonical integer index.
     *
     * @param mixed $rawgrade Raw AI grade value.
     * @param int|string[]|null $scale Forum grade payload from get_scale_payload().
     * @return int|float
     * @throws \moodle_exception When the grade cannot be resolved.
     */
    public static function normalize_review_grade(mixed $rawgrade, int|array|null $scale): int|float {
        if (is_int($scale) && $scale > 0) {
            if (!is_numeric($rawgrade)) {
                throw new \moodle_exception('error_invalidgrade', 'local_forum_ai');
            }

            return (float) $rawgrade;
        }

        if (is_int($scale) && $scale < 0) {
            $scale = self::get_scale_payload($scale);
        }

        if (is_array($scale)) {
            $rawgrade = trim((string) $rawgrade);

            if (filter_var($rawgrade, FILTER_VALIDATE_INT) !== false) {
                $index = (int) $rawgrade;
                if ($index >= 1 && $index <= count($scale)) {
                    return $index;
                }
            }

            foreach ($scale as $index => $option) {
                if (core_text::strtolower(trim((string) $option)) === core_text::strtolower($rawgrade)) {
                    return $index + 1;
                }
            }

            throw new \moodle_exception('error_invalidgrade', 'local_forum_ai');
        }

        if (is_numeric($rawgrade)) {
            return (float) $rawgrade;
        }

        throw new \moodle_exception('error_invalidgrade', 'local_forum_ai');
    }

    /**
     * Resolve the grade returned by the AI service into an applicable rating.
     *
     * A named-scale label is resolved to its 1-based index and out-of-range
     * values are rejected. Anything unresolvable yields null so that no rating
     * is applied, rather than a 0 that would reach the student gradebook as a
     * real mark. An explicit 0 is preserved.
     *
     * @param mixed $rawgrade Raw grade returned by the AI service.
     * @param int|array|null $scale Scale payload, or null when rating is off.
     * @return int|null Grade ready to be applied, or null when unresolvable.
     */
    public static function resolve_ai_grade(mixed $rawgrade, int|array|null $scale): ?int {
        if ($scale === null || $rawgrade === null || $rawgrade === '') {
            return null;
        }

        try {
            return (int) self::normalize_review_grade($rawgrade, $scale);
        } catch (\moodle_exception $e) {
            return null;
        }
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
     * Gets the global default delay for AI responses in minutes.
     *
     * @return int
     */
    public static function get_default_delay_minutes(): int {
        $raw = get_config('local_forum_ai', 'default_delayminutes');
        if ($raw === false || $raw === '') {
            return 60;
        }

        return max(1, (int) $raw);
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
     * Gets global default for "reply in locked discussions".
     *
     * @return bool
     */
    public static function get_default_reply_in_locked(): bool {
        $raw = get_config('local_forum_ai', 'default_replyinlocked');
        if ($raw === false || $raw === '') {
            return false;
        }

        return !empty($raw);
    }

    /**
     * Gets effective "reply in locked discussions" value using forum config or global fallback.
     *
     * @param \stdClass|null $config Forum config row.
     * @return bool
     */
    public static function get_effective_reply_in_locked(?\stdClass $config): bool {
        if ($config && isset($config->replyinlocked)) {
            $value = (int) $config->replyinlocked;
            if ($value !== self::REPLY_IN_LOCKED_INHERIT) {
                return !empty($value);
            }
        }

        return self::get_default_reply_in_locked();
    }

    /**
     * Checks whether a forum post is a private reply.
     *
     * Policy: the AI never replies to private replies. Core treats them as
     * leaves (replying to them is forbidden in capability checks, in
     * forum_add_new_post and in the UI), so the plugin skips them entirely.
     *
     * @param \stdClass $post Forum post record.
     * @return bool
     */
    public static function is_private_reply(\stdClass $post): bool {
        return !empty($post->privatereplyto);
    }

    /**
     * Checks whether the forum cut-off date has passed.
     *
     * Deliberately a date check, NOT a capability check: graders and admins
     * hold mod/forum:canoverridecutoff, so a capability gate would never
     * fire for the users who publish AI responses. Only cutoffdate gates;
     * duedate is advisory in core and does not block posting.
     *
     * @param \stdClass $forum Forum record.
     * @return bool
     */
    public static function is_forum_cutoff_reached(\stdClass $forum): bool {
        global $CFG;

        require_once($CFG->dirroot . '/mod/forum/lib.php');

        return forum_is_cutoff_date_reached($forum);
    }

    /**
     * Determines whether the AI may reply in the given discussion.
     *
     * Unlocked discussions always allow replies. Locked discussions
     * (either manually locked or locked by the forum inactivity rule)
     * only allow replies when the effective "reply in locked
     * discussions" option is enabled.
     *
     * @param \stdClass $forum Forum record.
     * @param \stdClass $discussion Discussion record.
     * @param \stdClass|null $config Forum config row.
     * @return bool
     */
    public static function can_reply_in_discussion(\stdClass $forum, \stdClass $discussion, ?\stdClass $config): bool {
        global $CFG;

        require_once($CFG->dirroot . '/mod/forum/lib.php');

        if (!forum_discussion_is_locked($forum, $discussion)) {
            return true;
        }

        return self::get_effective_reply_in_locked($config);
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
     * Rejected and expired responses are excluded from the count.
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
        // Expired rows are excluded like rejected ones: they were never published
        // (before the traceable 'expired' status they were deleted and never counted).
        $params = [
            'discussionid' => $discussionid,
            'rejected' => 'rejected',
            'expired' => 'expired',
        ] + $inparams;

        $sql = "SELECT COUNT(1)
                  FROM {local_forum_ai_pending}
                 WHERE discussionid = :discussionid
                   AND parentpostid $insql
                   AND status NOT IN (:rejected, :expired)";

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
     * Builds the thread context for a given post within a discussion.
     *
     * Collects all posts of the discussion created before the given post,
     * in chronological order, so the AI keeps the full conversation even
     * when students reply at the discussion (root) level instead of on
     * the branch that contains the previous AI replies.
     *
     * The list is capped to the root post (which defines the topic) plus
     * the most recent posts, to keep the payload bounded in long threads.
     *
     * Each entry contains the post id, chronological order, author
     * full name, and cleaned message text.
     *
     * @param int $discussionid Discussion ID.
     * @param int $postid Current post ID.
     * @param int $maxposts Maximum number of posts included in the context.
     * @return array List of thread entries with id, order, author, message.
     */
    public static function build_thread_context(
        int $discussionid,
        int $postid,
        int $maxposts = 20,
    ): array {
        global $DB;

        $currentpost = $DB->get_record(
            'forum_posts',
            ['id' => $postid, 'discussion' => $discussionid],
            'id,created',
            IGNORE_MISSING,
        );

        if (!$currentpost) {
            return [];
        }

        $posts = array_values(array_filter(
            self::get_visible_discussion_posts($discussionid),
            static function (
                \stdClass $post,
            ) use (
                $currentpost,
                $postid
            ): bool {
                return $post->created < $currentpost->created ||
                    ($post->created == $currentpost->created && $post->id < $postid);
            }
        ));

        // Cap the context: always keep the root post (topic) plus the most recent posts.
        if ($maxposts > 0 && count($posts) > $maxposts) {
            $root = array_shift($posts);
            $posts = array_merge([$root], array_slice($posts, -($maxposts - 1)));
        }

        $authornames = [];
        $threadentries = [];
        $order = 1;
        foreach ($posts as $post) {
            $cleaned = trim(strip_tags($post->message));
            if ($cleaned === '') {
                continue;
            }

            $authorid = (int)$post->userid;
            if (!array_key_exists($authorid, $authornames)) {
                $author = \core_user::get_user($authorid);
                // Never expose raw user ids to the AI; use a neutral label as fallback.
                $authornames[$authorid] = $author ? fullname($author) : 'Participant';
            }

            $threadentries[] = [
                'id' => (int)$post->id,
                'order' => $order,
                'author' => $authornames[$authorid],
                'message' => $cleaned,
            ];
            $order++;
        }

        return $threadentries;
    }

    /**
     * Returns the posts that are visible to a normal forum participant.
     *
     * Deleted posts and private replies are excluded.
     *
     * @param int $discussionid Discussion ID.
     * @return array<int, \stdClass>
     */
    public static function get_visible_discussion_posts(int $discussionid): array {
        global $DB;

        $posts = $DB->get_records_select(
            'forum_posts',
            'discussion = :discussionid AND privatereplyto = 0 AND deleted = 0',
            ['discussionid' => $discussionid],
            'created ASC, id ASC',
            'id,discussion,parent,userid,subject,message,messageformat,created,privatereplyto,deleted',
        );

        return array_values($posts);
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

        // Deleted posts and private replies are excluded: the payload must only
        // contain what a normal participant can see.
        $posts = $DB->get_records_sql("
            SELECT d.id, d.name, p.message
            FROM {forum_discussions} d
            JOIN {forum_posts} p ON p.discussion = d.id
            WHERE p.userid = ?
            AND d.forum = ?
            AND p.privatereplyto = 0
            AND p.deleted = 0
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
                // Whole forum grading setting (not the per-post ratings scale):
                // numeric maximum, or the option list for named scales.
                'scale' => self::get_scale_payload((int)$forum->grade_forum) ?? 0,
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
