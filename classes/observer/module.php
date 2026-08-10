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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../locallib.php');

use core\event\course_module_created;
use core\event\course_module_deleted;

/**
 * Observer for course module events.
 *
 * @package    local_forum_ai
 * @category   event
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module {
    /**
     * Handles creation of "single type" forums.
     *
     * Queues an adhoc task to process the single forum discussion asynchronously,
     * avoiding blocking the main execution thread.
     *
     * @param course_module_created $event The event triggered when a module is created.
     * @return bool True on success or when no action is needed.
     */
    public static function course_module_created(course_module_created $event): bool {
        global $DB;

        try {
            // Only process forum modules.
            if ($event->other['modulename'] !== 'forum') {
                return true;
            }

            $forumid = $event->other['instanceid'];
            $forum = $DB->get_record('forum', ['id' => $forumid], '*', MUST_EXIST);

            // Only process single-type forums.
            if ($forum->type !== 'single') {
                return true;
            }

            // Queue an adhoc task to process the discussion asynchronously.
            $task = new \local_forum_ai\task\process_single_forum_discussion();
            $task->set_custom_data([
                'forumid' => $forumid,
                'courseid' => $event->courseid,
                'contextid' => $event->get_context()->id,
                'retries' => 0,
            ]);

            // Schedule the task to run in 2 seconds to allow discussion creation.
            $task->set_next_run_time(time() + 2);

            \core\task\manager::queue_adhoc_task($task);

            return true;
        } catch (\Exception $e) {
            debugging('Error in course_module_created observer: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return true;
        }
    }

    /**
     * Triggered when a course module is deleted.
     *
     * Cleans up forum AI related data when a forum is deleted.
     *
     * @param course_module_deleted $event The event triggered when a module is deleted.
     * @return void
     */
    public static function forum_deleted(course_module_deleted $event): void {
        global $DB;

        // Only process forum modules.
        if (!isset($event->other['modulename']) || $event->other['modulename'] !== 'forum') {
            return;
        }

        if (!isset($event->other['instanceid'])) {
            debugging('forum_deleted: missing instanceid in event->other', DEBUG_DEVELOPER);
            return;
        }

        $forumid = $event->other['instanceid'];

        // Clean up all forum AI related records.
        $DB->delete_records('local_forum_ai_config', ['forumid' => $forumid]);
        $DB->delete_records('local_forum_ai_pending', ['forumid' => $forumid]);

        // Delayed queue rows of this forum would otherwise be retried forever.
        // Both payload types ('post' and 'discussion') carry the cmid, which for
        // course_module_deleted is the event objectid; match it by exact decoded value.
        $cmid = (int) $event->objectid;
        $rows = $DB->get_records('local_forum_ai_queue', null, '', 'id, payload');
        foreach ($rows as $row) {
            $data = json_decode($row->payload);
            if (is_object($data) && isset($data->cmid) && (int) $data->cmid === $cmid) {
                $DB->delete_records('local_forum_ai_queue', ['id' => $row->id]);
            }
        }
    }
}
