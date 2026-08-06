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
 * Restore plugin for local_forum_ai.
 *
 * @package    local_forum_ai
 * @copyright  2025 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_forum_ai_plugin extends restore_local_plugin {
    /** @var array Temporary configuration data. */
    protected $tempconfigs = [];

    /** @var array Temporary pending data. */
    protected $temppendings = [];

    /**
     * Define restore paths.
     *
     * @return restore_path_element[]
     */
    protected function define_course_plugin_structure() {
        return [
            new restore_path_element(
                'local_forum_ai_config',
                $this->get_pathfor('/forum_ai_configs/forum_ai_config')
            ),
            new restore_path_element(
                'local_forum_ai_pending',
                $this->get_pathfor('/forum_ai_pendings/forum_ai_pending')
            ),
        ];
    }

    /**
     * Store each configuration record temporarily.
     *
     * @param array $data Configuration data.
     */
    public function process_local_forum_ai_config($data) {
        $this->tempconfigs[] = (object)$data;
    }

    /**
     * Store each pending record temporarily.
     *
     * @param array $data Pending data.
     */
    public function process_local_forum_ai_pending($data) {
        $this->temppendings[] = (object)$data;
    }

    /**
     * After the course has been fully restored (forums included),
     * insert the actual data using the new IDs.
     */
    public function after_restore_course() {
        global $DB;

        mtrace(">> [local_forum_ai] Restoring configuration and pending data...");

        // Restore configurations.
        foreach ($this->tempconfigs as $config) {
            $newforumid = $this->get_mappingid('forum', $config->forumid);
            if (!$newforumid) {
                mtrace("   - Skipped config (original forum {$config->forumid} not mapped)");
                continue;
            }

            // Keep any existing restored/manual config row intact. Restore only seeds
            // config when the forum does not already have one.
            if ($DB->record_exists('local_forum_ai_config', ['forumid' => $newforumid])) {
                mtrace("   - Existing config kept for forum={$newforumid}");
                continue;
            }

            $record = new stdClass();
            $record->forumid = $newforumid;
            $record->enabled = $config->enabled;
            $record->reply_message = $config->reply_message;
            $record->require_approval = $config->require_approval;
            $record->allowedroles = $config->allowedroles ?? null;
            $record->enablediainitconversation = $config->enablediainitconversation ?? 0;
            $record->questionturns = isset($config->questionturns) ? max(0, min(3, (int)$config->questionturns)) : 1;
            $record->graderid = !empty($config->graderid)
                ? $this->get_mappingid('user', $config->graderid, null)
                : null;
            $record->usedelay = $config->usedelay ?? 0;
            $record->delayminutes = isset($config->delayminutes)
                ? max(1, (int)$config->delayminutes)
                : \local_forum_ai\utils::get_default_delay_minutes();
            $record->replyinlocked = $config->replyinlocked ?? 0;
            $record->timecreated = $config->timecreated;
            $record->timemodified = $config->timemodified;

            $DB->insert_record('local_forum_ai_config', $record);
            mtrace("   + Config restored for forum={$newforumid}");
        }

        // Restore pendings.
        foreach ($this->temppendings as $pending) {
            $newforumid = $this->get_mappingid('forum', $pending->forumid);
            $newdiscussionid = $this->get_mappingid('forum_discussion', $pending->discussionid);
            $newuserid = $this->get_mappingid('user', $pending->creator_userid);

            if (!$newforumid || !$newdiscussionid) {
                mtrace("   - Skipped pending (missing forum/discussion mapping)");
                continue;
            }

            $record = new stdClass();
            $record->forumid = $newforumid;
            $record->discussionid = $newdiscussionid;
            $record->creator_userid = $newuserid ?? $pending->creator_userid;
            // Remap the approver/rejecter too; the reference is dropped when the
            // user did not survive the restore (nullable column).
            $record->action_userid = !empty($pending->action_userid)
                ? ($this->get_mappingid('user', $pending->action_userid) ?: null)
                : null;
            $record->parentpostid = !empty($pending->parentpostid)
                ? ($this->get_mappingid('forum_post', $pending->parentpostid) ?: null)
                : null;
            $record->subject = $pending->subject;
            $record->grade = isset($pending->grade) ? (int) $pending->grade : null;
            // Restored AI messages may come from pre-sanitization backups: purify on re-insertion.
            $record->message = clean_text($pending->message ?? '', FORMAT_HTML);
            // Map the published post to its restored id; null when it did not survive.
            $mappedpostid = !empty($pending->postid)
                ? ($this->get_mappingid('forum_post', $pending->postid) ?: null)
                : null;
            $record->postid = $mappedpostid;
            $record->status = in_array($pending->status ?? null, ['pending', 'approved', 'rejected', 'expired'], true)
                ? $pending->status
                : 'pending';
            $record->approval_token = md5(uniqid('restored_', true));
            $record->timecreated = $pending->timecreated;
            $record->timemodified = time();
            $record->approved_at = property_exists($pending, 'approved_at') ? $pending->approved_at : null;

            $DB->insert_record('local_forum_ai_pending', $record);
            mtrace("   + Pending restored for forum={$newforumid}, discussion={$newdiscussionid}");
        }

        mtrace(">> [local_forum_ai] Restoration finished ✅");
    }
}
