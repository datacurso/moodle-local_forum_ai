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

use local_forum_ai\local\provider_state;

/**
 * Adhoc task to process single forum discussions asynchronously.
 *
 * This task waits for the discussion to be created in a single-type forum
 * and then processes it for AI features without creating artificial events.
 *
 * @package    local_forum_ai
 * @category   task
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_single_forum_discussion extends \core\task\adhoc_task {
    /**
     * Maximum number of retry attempts.
     */
    const MAX_RETRIES = 3;

    /**
     * Delay in seconds between retry attempts.
     */
    const RETRY_DELAY = 3;

    /**
     * Execute the task.
     *
     * Attempts to find the discussion associated with the single-type forum.
     * If not found, it will retry up to MAX_RETRIES times.
     * Once found, processes it directly without creating artificial events.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();

        // Validate required data.
        if (!isset($data->forumid) || !isset($data->courseid) || !isset($data->contextid)) {
            mtrace('process_single_forum_discussion: Missing required custom data');
            return;
        }

        // Skip before any retry is queued while the plugin is disabled in the provider for the tenant of the user
        // who created the forum, never the tenant of the course nor the cron user. Tasks queued before the creator
        // was stored fall back to the task user, else to the site default; the tenant of the discussion author is
        // checked again by observer\discussion::process_discussion().
        $creatorid = (int) ($data->userid ?? $this->get_userid() ?? 0);
        if (!provider_state::is_enabled(\local_forum_ai\utils::get_user_tenant_id($creatorid))) {
            mtrace(get_string('task_skipped_plugindisabled', 'local_forum_ai'));
            return;
        }

        try {
            // Attempt to retrieve the discussion for this forum.
            $discussion = $DB->get_record(
                'forum_discussions',
                ['forum' => $data->forumid],
                '*',
                IGNORE_MULTIPLE
            );

            if (!$discussion) {
                // Discussion not found, check if we should retry.
                $retries = isset($data->retries) ? $data->retries : 0;

                if ($retries < self::MAX_RETRIES) {
                    // Requeue the task for another attempt.
                    mtrace("Discussion not found for forum {$data->forumid}. Retry {$retries}/" . self::MAX_RETRIES);

                    // The retry keeps the creator, so it gates on the same tenant as this run.
                    $newtask = new self();
                    $data->userid = $creatorid;
                    $data->retries = $retries + 1;
                    $newtask->set_custom_data($data);
                    $newtask->set_next_run_time(time() + self::RETRY_DELAY);

                    \core\task\manager::queue_adhoc_task($newtask);
                } else {
                    // Max retries reached, log and give up.
                    mtrace("Discussion not found for forum {$data->forumid} after " . self::MAX_RETRIES . " retries. Giving up.");
                    debugging(
                        "process_single_forum_discussion: Discussion not created for forum {$data->forumid} after max retries",
                        DEBUG_NORMAL
                    );
                }

                return;
            }

            // Discussion found, proceed with AI processing.
            mtrace("Processing discussion {$discussion->id} for single forum {$data->forumid}");

            // Get the context object.
            $context = \context::instance_by_id($data->contextid);

            \local_forum_ai\observer\discussion::process_discussion(
                $discussion->id,
                $data->forumid,
                $data->courseid,
                $context
            );

            mtrace("Successfully processed discussion {$discussion->id}");
        } catch (\dml_exception $e) {
            // Database error occurred.
            mtrace("Database error in process_single_forum_discussion: " . $e->getMessage());
            debugging(
                "process_single_forum_discussion database error: " . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        } catch (\Exception $e) {
            // General error occurred.
            mtrace("Error in process_single_forum_discussion: " . $e->getMessage());
            debugging(
                "process_single_forum_discussion error: " . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Get a descriptive name for this task (for display in the admin UI).
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_process_single_forum_discussion', 'local_forum_ai');
    }
}
