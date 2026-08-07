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

use local_forum_ai\task\process_ai_discussion;
use local_forum_ai\task\process_ai_post;
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
     * Lock type used to serialize queue dispatch.
     */
    private const LOCKTYPE = 'local_forum_ai_queue';

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
        $lockfactory = \core\lock\lock_config::get_lock_factory(self::LOCKTYPE);

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
            $lock = $lockfactory->get_lock('queueitem_' . $item->id, 0);
            if (!$lock) {
                continue;
            }

            try {
                $this->dispatch_item($item);
            } catch (\Exception $e) {
                // Only runtime failures are skipped. A programming error is left to
                // propagate so cron records the task as failed instead of hiding it.
                debugging(
                    'Error processing Forum AI queue item ' . $item->id . ': ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * Queue the adhoc task for one queue item and drop the item from the queue.
     *
     * Items whose post or discussion no longer exists can never be dispatched,
     * so they are discarded instead of failing again on every later run.
     *
     * @param \stdClass $item Queue row.
     */
    private function dispatch_item(\stdClass $item): void {
        global $DB;

        $data = json_decode($item->payload);
        $authorid = $this->resolve_author_id($item->type, $data);

        if ($authorid === null) {
            debugging(
                'Discarding Forum AI queue item ' . $item->id . ': unusable payload or missing target.',
                DEBUG_DEVELOPER
            );
            $DB->delete_records('local_forum_ai_queue', ['id' => $item->id]);
            return;
        }

        $transaction = $DB->start_delegated_transaction();

        try {
            $task = $item->type === 'post' ? new process_ai_post() : new process_ai_discussion();
            $task->set_custom_data($data);
            $task->set_component('local_forum_ai');
            $task->set_userid($authorid);
            \core\task\manager::queue_adhoc_task($task);

            // Dispatched rows are never read again, so keeping them only grows the table.
            $DB->delete_records('local_forum_ai_queue', ['id' => $item->id]);

            $transaction->allow_commit();
        } catch (\Exception $e) {
            // rollback_delegated_transaction() always rethrows; execute() logs it.
            $transaction->rollback($e);
        }
    }

    /**
     * Resolve the author of the post or discussion a queue item refers to.
     *
     * @param string $type Queue item type, either 'post' or 'discussion'.
     * @param mixed $data Decoded queue payload.
     * @return int|null Author id, or null when the target cannot be resolved.
     */
    private function resolve_author_id(string $type, mixed $data): ?int {
        global $DB;

        if (!is_object($data)) {
            return null;
        }

        if ($type === 'post') {
            $table = 'forum_posts';
            $targetid = (int) ($data->postid ?? 0);
        } else if ($type === 'discussion') {
            $table = 'forum_discussions';
            $targetid = (int) ($data->discussionid ?? 0);
        } else {
            return null;
        }

        if ($targetid <= 0) {
            return null;
        }

        $authorid = $DB->get_field($table, 'userid', ['id' => $targetid], IGNORE_MISSING);

        return $authorid === false ? null : (int) $authorid;
    }
}
