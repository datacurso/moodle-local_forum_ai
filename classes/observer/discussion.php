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

namespace local_forum_ai\observer;

use mod_forum\event\discussion_created;
use mod_forum\event\discussion_deleted;
use local_forum_ai\task\process_ai_discussion;
use local_forum_ai\utils;

/**
 * Observer for discussion events.
 *
 * @package    local_forum_ai
 * @category   event
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discussion {
    /**
     * Handles discussion creation events.
     *
     * Queues an adhoc task to process the discussion with AI features.
     *
     * @param discussion_created $event The discussion created event.
     * @return bool True on success, false on error.
     */
    public static function discussion_created(discussion_created $event): bool {
        if (!utils::is_feature_enabled()) {
            return true;
        }

        if (!utils::is_global_ai_enabled()) {
            return true;
        }

        try {
            $data = $event->get_data();
            $discussionid = $data['objectid'];
            $forumid = $data['other']['forumid'];
            $courseid = $data['courseid'];
            $context = $event->get_context();

            self::process_discussion($discussionid, $forumid, $courseid, $context);

            return true;
        } catch (\Throwable $e) {
            debugging('Error in discussion_created observer: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return true;
        }
    }

    /**
     * Processes a discussion for AI features.
     *
     * This method contains the actual logic for processing discussions and can be called
     * both from the observer and from other places (like adhoc tasks) without creating
     * artificial events.
     *
     * @param int $discussionid ID of the discussion.
     * @param int $forumid ID of the forum.
     * @param int $courseid ID of the course.
     * @param \context $context Context of the forum.
     * @return void
     */
    public static function process_discussion(int $discussionid, int $forumid, int $courseid, \context $context): void {
        global $DB;

        if (!utils::is_feature_enabled()) {
            return;
        }

        if (!utils::is_global_ai_enabled()) {
            return;
        }

        try {
            // Get discussion, forum and course records.
            $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid], '*', MUST_EXIST);
            $forum = $DB->get_record('forum', ['id' => $forumid], '*', MUST_EXIST);
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

            $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

            $config = $DB->get_record('local_forum_ai_config', ['forumid' => $forumid]);

            if (!$config || empty($config->enabled)) {
                return;
            }

            $requireapproval = (int)($config->require_approval ?? 1);

            // Delayed review settings only apply when approvals are automatic.
            if ($requireapproval === 0 && !empty($config->usedelay)) {
                $delay = max(1, (int) $config->delayminutes);
                $timetoprocess = time() + ($delay * 60);

                $taskdata = new \stdClass();
                $taskdata->discussionid = $discussionid;
                $taskdata->cmid = $cm->id;

                $DB->insert_record('local_forum_ai_queue', (object) [
                    'type' => 'discussion',
                    'payload' => json_encode($taskdata),
                    'timecreated' => time(),
                    'timetoprocess' => $timetoprocess,
                    'processed' => 0,
                ]);

                return;
            }

            // Prepare data for ad-hoc task.
            $taskdata = new \stdClass();
            $taskdata->discussionid = $discussionid;
            $taskdata->cmid = $cm->id;

            // Create and queue the ad-hoc task for AI processing.
            $task = new process_ai_discussion();
            $task->set_custom_data($taskdata);
            $task->set_component('local_forum_ai');
            $task->set_userid($discussion->userid);

            \core\task\manager::queue_adhoc_task($task);
        } catch (\dml_missing_record_exception $e) {
            debugging(
                'Missing record in process_discussion: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        } catch (\Throwable $e) {
            debugging(
                'Error processing discussion for AI: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Triggered when a discussion is deleted.
     *
     * Cleans up AI-related pending records for the deleted discussion.
     *
     * @param discussion_deleted $event The event triggered when a discussion is deleted.
     * @return void
     */
    public static function discussion_deleted(discussion_deleted $event): void {
        global $DB;

        $discussionid = (int) $event->objectid;

        $DB->delete_records('local_forum_ai_pending', ['discussionid' => $discussionid]);

        $like1 = '%"discussionid":' . $discussionid . '%';
        $like2 = '%"discussionid":"' . $discussionid . '"%';

        $sql = "DELETE FROM {local_forum_ai_queue}
            WHERE type = :type
              AND (payload LIKE :like1 OR payload LIKE :like2)";

        $params = [
            'type' => 'discussion',
            'like1' => $like1,
            'like2' => $like2,
        ];

        $DB->execute($sql, $params);
    }
}
