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

use local_forum_ai\approval;
use local_forum_ai\utils;

/**
 * Scheduled task that reminds the approvers about AI responses still waiting for review.
 *
 * Every pending response older than the reminder interval is reminded once per interval,
 * until somebody approves or rejects it.
 *
 * @package    local_forum_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remind_pending_approvals extends \core\task\scheduled_task {
    /**
     * Minimum age of a pending response, and time between two reminders for it.
     */
    public const REMINDER_INTERVAL = 3 * DAYSECS;

    /**
     * Maximum number of responses reminded per run, so one run stays bounded.
     */
    private const BATCH_SIZE = 200;

    /**
     * Return the task name shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_remind_pending_approvals', 'local_forum_ai');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        if (!utils::is_feature_enabled()) {
            return;
        }

        $cutoff = time() - self::REMINDER_INTERVAL;
        $pendings = $DB->get_records_select(
            'local_forum_ai_pending',
            'status = :status AND timecreated <= :created AND timeremindered <= :reminded',
            ['status' => 'pending', 'created' => $cutoff, 'reminded' => $cutoff],
            'timecreated ASC',
            '*',
            0,
            self::BATCH_SIZE
        );

        foreach ($pendings as $pending) {
            // Only stamp the row when somebody was reminded, so a response without
            // reachable approvers is retried on the next run.
            if (approval::send_reminder($pending)) {
                $DB->set_field('local_forum_ai_pending', 'timeremindered', time(), ['id' => $pending->id]);
            }
        }
    }
}
