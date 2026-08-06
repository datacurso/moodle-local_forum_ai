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

/**
 * Data generator for local_forum_ai.
 *
 * @package    local_forum_ai
 * @category   test
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_forum_ai_generator extends component_generator_base {
    /**
     * Creates a pending AI response record in local_forum_ai_pending.
     *
     * Required fields: discussionid, forumid, creator_userid.
     * Optional fields with defaults: parentpostid (discussion first post),
     * postid (null), subject, message, grade (null), status ('pending'),
     * approval_token (random unique hash), timecreated/timemodified (now).
     *
     * @param array|stdClass $record Record data.
     * @return stdClass The inserted record.
     */
    public function create_pending_response($record = null): stdClass {
        global $DB;

        $record = (array) $record;

        if (empty($record['discussionid'])) {
            throw new coding_exception('create_pending_response() requires a discussionid.');
        }
        $discussion = $DB->get_record('forum_discussions', ['id' => $record['discussionid']], '*', MUST_EXIST);

        if (empty($record['forumid'])) {
            $record['forumid'] = $discussion->forum;
        }

        if (empty($record['creator_userid'])) {
            $record['creator_userid'] = $discussion->userid;
        }

        $now = time();
        $pending = (object) [
            'discussionid' => (int) $record['discussionid'],
            'forumid' => (int) $record['forumid'],
            'parentpostid' => isset($record['parentpostid'])
                ? (int) $record['parentpostid']
                : (int) $discussion->firstpost,
            'postid' => isset($record['postid']) ? (int) $record['postid'] : null,
            'creator_userid' => (int) $record['creator_userid'],
            'subject' => $record['subject'] ?? ('Re: ' . $discussion->name),
            'message' => $record['message'] ?? '<p>AI generated response for testing.</p>',
            'grade' => (isset($record['grade']) && $record['grade'] !== '') ? (int) $record['grade'] : null,
            'status' => $record['status'] ?? 'pending',
            'approval_token' => $record['approval_token'] ?? self::generate_token(),
            'timecreated' => $record['timecreated'] ?? $now,
            'timemodified' => $record['timemodified'] ?? $now,
            'approved_at' => $record['approved_at'] ?? null,
        ];

        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);

        return $pending;
    }

    /**
     * Creates a forum AI configuration record in local_forum_ai_config.
     *
     * Required field: forumid. Everything else defaults to a sane
     * "AI enabled with manual approval" configuration.
     *
     * @param array|stdClass $record Record data.
     * @return stdClass The inserted record.
     */
    public function create_config($record = null): stdClass {
        global $DB;

        $record = (array) $record;

        if (empty($record['forumid'])) {
            throw new coding_exception('create_config() requires a forumid.');
        }

        $now = time();
        $config = (object) [
            'forumid' => (int) $record['forumid'],
            'enabled' => isset($record['enabled']) ? (int) $record['enabled'] : 1,
            'enablediainitconversation' => isset($record['enablediainitconversation'])
                ? (int) $record['enablediainitconversation'] : 0,
            'questionturns' => isset($record['questionturns']) ? (int) $record['questionturns'] : 1,
            'allowedroles' => $record['allowedroles'] ?? null,
            'reply_message' => $record['reply_message'] ?? 'Reply with an empathetic and motivational tone',
            'require_approval' => isset($record['require_approval']) ? (int) $record['require_approval'] : 1,
            'graderid' => isset($record['graderid']) && $record['graderid'] !== '' ? (int) $record['graderid'] : null,
            'usedelay' => isset($record['usedelay']) ? (int) $record['usedelay'] : 0,
            'delayminutes' => isset($record['delayminutes']) ? (int) $record['delayminutes'] : 60,
            'replyinlocked' => isset($record['replyinlocked'])
                ? (int) $record['replyinlocked']
                : \local_forum_ai\utils::REPLY_IN_LOCKED_INHERIT,
            'timecreated' => $record['timecreated'] ?? $now,
            'timemodified' => $record['timemodified'] ?? $now,
        ];

        $config->id = $DB->insert_record('local_forum_ai_config', $config);

        return $config;
    }

    /**
     * Generates a random unique approval token (64 char hash).
     *
     * @return string
     */
    private static function generate_token(): string {
        return hash('sha256', uniqid('local_forum_ai', true) . random_string(20));
    }
}
