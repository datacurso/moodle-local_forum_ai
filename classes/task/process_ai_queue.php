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

namespace local_forum_ai\task;

use local_forum_ai\task\process_ai_post;
use local_forum_ai\task\process_ai_discussion;
use local_forum_ai\utils;

/**
 * Scheduled task to process delayed AI queue.
 *
 * @package    local_forum_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_ai_queue extends \core\task\scheduled_task {
    /**
     * Return the task name shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_process_ai_queue', 'local_forum_ai');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        if (!utils::is_feature_enabled()) {
            return;
        }

        if (!utils::is_global_ai_enabled()) {
            return;
        }

        $now = time();

        // Get pending items whose time has arrived.
        $items = $DB->get_records_select(
            'local_forum_ai_queue',
            'processed = 0 AND timetoprocess <= ?',
            [$now],
            'timetoprocess ASC',
            '*',
            0,
            20
        );

        foreach ($items as $item) {
            $data = json_decode($item->payload);

            try {
                if ($item->type === 'post') {
                    $task = new process_ai_post();
                    $task->set_custom_data($data);
                    \core\task\manager::queue_adhoc_task($task);
                } else if ($item->type === 'discussion') {
                    $task = new process_ai_discussion();
                    $task->set_custom_data($data);
                    \core\task\manager::queue_adhoc_task($task);
                }

                $item->processed = 1;
                $DB->update_record('local_forum_ai_queue', $item);
            } catch (\Throwable $e) {
                debugging('Error processing Forum AI queue: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
