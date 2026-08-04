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

use mod_forum\event\post_created;
use mod_forum\event\post_deleted;
use local_forum_ai\task\process_ai_post;
use local_forum_ai\utils;

/**
 * Forum post event observer for AI integration.
 *
 * @package local_forum_ai
 * @copyright 2025 Datacurso
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class post {
    /**
     * Handles the post_created event when a user replies to a forum discussion.
     *
     * @param post_created $event Forum post created event.
     * @return bool Always returns true to prevent Moodle event interruption.
     */
    public static function post_created(post_created $event): bool {
        global $DB;

        if (!utils::is_feature_enabled()) {
            return true;
        }

        if (!utils::is_global_ai_enabled()) {
            return true;
        }

        try {
            $data = $event->get_data();
            $postid = $data['objectid'];

            $post = $DB->get_record('forum_posts', ['id' => $postid], '*', MUST_EXIST);
            $discussion = $DB->get_record('forum_discussions', ['id' => $post->discussion], '*', MUST_EXIST);
            $forum = $DB->get_record('forum', ['id' => $discussion->forum], '*', MUST_EXIST);
            $course = $DB->get_record('course', ['id' => $forum->course], '*', MUST_EXIST);

            // Get course module.
            $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

            $config = $DB->get_record('local_forum_ai_config', ['forumid' => $forum->id]);

            if (!$config || empty($config->enabled)) {
                return true;
            }

            $taskdata = (object) [
                'postid' => $postid,
                'cmid' => $cm->id,
            ];

            $requireapproval = (int)($config->require_approval ?? 1);

            // Delayed review settings only apply when approvals are automatic.
            if ($requireapproval === 0 && !empty($config->usedelay)) {
                $delay = max(1, (int) $config->delayminutes);
                $timetoprocess = time() + ($delay * 60);

                $DB->insert_record('local_forum_ai_queue', (object) [
                    'type' => 'post',
                    'payload' => json_encode($taskdata),
                    'timecreated' => time(),
                    'timetoprocess' => $timetoprocess,
                    'processed' => 0,
                ]);
            } else {
                $task = new process_ai_post();
                $task->set_custom_data($taskdata);
                $task->set_component('local_forum_ai');
                $task->set_userid($post->userid);
                \core\task\manager::queue_adhoc_task($task);
            }

            return true;
        } catch (\Throwable $e) {
            debugging('Error queueing AI response task: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return true;
        }
    }

    /**
     * Handles the post_deleted event when a forum post is removed.
     *
     * @param post_deleted $event Forum post deleted event.
     * @return void
     */
    public static function post_deleted(post_deleted $event): void {
        global $DB;

        $postid = (int) $event->objectid;

        $DB->delete_records('local_forum_ai_pending', ['parentpostid' => $postid]);

        $like1 = '%"postid":' . $postid . '%';
        $like2 = '%"postid":"' . $postid . '"%';

        $sql = "DELETE FROM {local_forum_ai_queue}
            WHERE type = :type
              AND (payload LIKE :like1 OR payload LIKE :like2)";

        $params = [
            'type' => 'post',
            'like1' => $like1,
            'like2' => $like2,
        ];

        $DB->execute($sql, $params);
    }
}
