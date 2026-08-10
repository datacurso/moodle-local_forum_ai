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

/**
 * Tests for the backup and restore of local_forum_ai pending records.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai\backup;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/local/forum_ai/backup/moodle2/restore_local_forum_ai_plugin.class.php');
require_once(__DIR__ . '/restore_local_forum_ai_plugin_test_double.php');

use local_forum_ai\external\approve_response;

/**
 * Full-cycle backup/restore regression test for restored AI pending rows.
 *
 * Covers: MDL-INT-026 — Copia de seguridad y restauracion del curso
 *
 * @group local_forum_ai
 * @covers \backup_local_forum_ai_plugin::define_course_plugin_structure
 * @covers \restore_local_forum_ai_plugin::after_restore_course
 */
final class backup_restore_test extends \advanced_testcase {
    /**
     * Pending responses keep their reply target and grade across backup/restore.
     */
    public function test_pending_response_round_trips_with_reply_target_and_grade(): void {
        global $CFG, $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $CFG->keeptempdirectoriesonbackup = true;
        $this->expectOutputRegex('/.*/s');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $grader = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'name' => 'Backup restore forum',
            'assessed' => 1,
            'scale' => 100,
        ]);
        $forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $forummodule->id, $course->id, false, MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
            'name' => 'Backup restore thread',
        ]);
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $reply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
        ]);
        $reply = $DB->get_record('forum_posts', ['id' => $reply->id], '*', MUST_EXIST);

        $otherreply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
        ]);
        $otherreply = $DB->get_record('forum_posts', ['id' => $otherreply->id], '*', MUST_EXIST);

        $config = $DB->get_record('local_forum_ai_config', ['forumid' => $forum->id]) ?: new \stdClass();
        $config->forumid = $forum->id;
        $config->enabled = 1;
        $config->require_approval = 1;
        $config->graderid = $grader->id;
        $config->reply_message = 'AI prompt';
        $config->timemodified = time();

        if (empty($config->id)) {
            $config->timecreated = time();
            $DB->insert_record('local_forum_ai_config', $config);
        } else {
            $DB->update_record('local_forum_ai_config', $config);
        }

        $pending = (object) [
            'discussionid' => $discussion->id,
            'forumid' => $forum->id,
            'parentpostid' => $otherreply->id,
            'creator_userid' => $student->id,
            'subject' => 'Re: ' . $discussion->name,
            'message' => '<p>AI response to restore</p>',
            'grade' => 73,
            'status' => 'pending',
            'approval_token' => md5(uniqid('backup_pending_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);

        $approved = (object) [
            'discussionid' => $discussion->id,
            'forumid' => $forum->id,
            'parentpostid' => $otherreply->id,
            'creator_userid' => $student->id,
            'subject' => 'Re: ' . $discussion->name,
            'message' => '<p>AI response to approve</p>',
            'grade' => 61,
            'status' => 'pending',
            'approval_token' => md5(uniqid('backup_approved_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $approved->id = $DB->insert_record('local_forum_ai_pending', $approved);

        $rejected = (object) [
            'discussionid' => $discussion->id,
            'forumid' => $forum->id,
            'parentpostid' => $otherreply->id,
            'creator_userid' => $student->id,
            'subject' => 'Re: ' . $discussion->name,
            'message' => '<p>AI response to reject</p>',
            'grade' => 0,
            'status' => 'pending',
            'approval_token' => md5(uniqid('backup_rejected_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $rejected->id = $DB->insert_record('local_forum_ai_pending', $rejected);

        $approvedresult = approve_response::execute($approved->approval_token, 'approve');
        $this->assertTrue($approvedresult['success']);
        $approved = $DB->get_record('local_forum_ai_pending', ['id' => $approved->id], '*', MUST_EXIST);

        $rejectedresult = approve_response::execute($rejected->approval_token, 'reject');
        $this->assertTrue($rejectedresult['success']);
        $rejected = $DB->get_record('local_forum_ai_pending', ['id' => $rejected->id], '*', MUST_EXIST);

        $backupcontroller = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $backupid = $backupcontroller->get_backupid();
        $backupcontroller->execute_plan();
        $backupcontroller->destroy();

        $newcourseid = \restore_dbops::create_new_course(
            'Restored course',
            'RESTORED' . $course->id,
            $course->category
        );
        $restorecontroller = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($restorecontroller->execute_precheck());
        $restorecontroller->execute_plan();
        $restorecontroller->destroy();

        $restoredforum = $DB->get_record(
            'forum',
            ['course' => $newcourseid, 'name' => 'Backup restore forum'],
            '*',
            MUST_EXIST
        );
        $restoredconfig = $DB->get_record('local_forum_ai_config', ['forumid' => $restoredforum->id], '*', MUST_EXIST);
        $restoreddiscussion = $DB->get_record('forum_discussions', ['forum' => $restoredforum->id], '*', MUST_EXIST);

        $restoredpending = $DB->get_record(
            'local_forum_ai_pending',
            ['approval_token' => $pending->approval_token],
            '*',
            MUST_EXIST
        );
        $restoredapproved = $DB->get_record(
            'local_forum_ai_pending',
            ['approval_token' => $approved->approval_token],
            '*',
            MUST_EXIST
        );
        $restoredrejected = $DB->get_record(
            'local_forum_ai_pending',
            ['approval_token' => $rejected->approval_token],
            '*',
            MUST_EXIST
        );

        $this->assertSame(73, (int) $restoredpending->grade);
        $this->assertSame('pending', $restoredpending->status);

        $this->assertSame(1, (int) $restoredconfig->enabled);
        $this->assertSame(1, (int) $restoredconfig->require_approval);
        $this->assertSame((int) $grader->id, (int) $restoredconfig->graderid);
        $this->assertSame('AI prompt', $restoredconfig->reply_message);

        $this->assertSame('approved', $restoredapproved->status);
        $this->assertSame((int) $approved->approved_at, (int) $restoredapproved->approved_at);

        $restoredpendingreply = $DB->get_record('forum_posts', ['id' => $restoredpending->parentpostid], '*', MUST_EXIST);
        $restoredotherreply = $DB->get_record('forum_posts', ['id' => $restoredapproved->parentpostid], '*', MUST_EXIST);

        $this->assertSame((int) $restoredpendingreply->id, (int) $restoredpending->parentpostid);
        $this->assertSame((int) $restoredotherreply->id, (int) $restoredapproved->parentpostid);

        $this->assertSame('rejected', $restoredrejected->status);
        $this->assertNull($restoredrejected->approved_at);
        $this->assertSame((int) $restoredotherreply->id, (int) $restoredrejected->parentpostid);

        foreach (['pending', 'approved', 'rejected'] as $status) {
            $this->assertSame(1, $DB->count_records('local_forum_ai_pending', [
                'forumid' => $restoredforum->id,
                'status' => $status,
            ]));
        }

        $pendingresult = approve_response::execute($restoredpending->approval_token, 'approve');
        $this->assertTrue($pendingresult['success']);

        $newpostid = (int) $DB->get_field(
            'local_forum_ai_pending',
            'postid',
            ['id' => $restoredpending->id],
            MUST_EXIST
        );
        $newpost = $DB->get_record('forum_posts', ['id' => $newpostid], '*', MUST_EXIST);

        $managedpostcount = $DB->count_records('forum_posts', ['discussion' => $restoreddiscussion->id]);

        $this->assertSame((int) $restoredpendingreply->id, (int) $newpost->parent);

        foreach ([$restoredapproved, $restoredrejected] as $managedrecord) {
            try {
                approve_response::execute($managedrecord->approval_token, 'approve');
                $this->fail('Managed restored responses must not be re-approved.');
            } catch (\dml_missing_record_exception $e) {
                $this->assertSame($managedpostcount, $DB->count_records('forum_posts', ['discussion' => $restoreddiscussion->id]));
            }
        }

        $rating = $DB->get_record('rating', ['itemid' => $restoredpendingreply->id], '*', MUST_EXIST);
        $this->assertSame(73, (int) $rating->rating);
    }

    /**
     * A backup taken without user data must not carry the per-student AI responses.
     */
    public function test_backup_without_user_data_excludes_pending_responses(): void {
        global $CFG, $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $this->expectOutputRegex('/.*/s');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $forummodule = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'name' => 'No user data forum',
        ]);
        $forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
        ]);
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $grader = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $DB->insert_record('local_forum_ai_config', (object) [
            'forumid' => $forum->id,
            'enabled' => 1,
            'require_approval' => 0,
            'graderid' => $grader->id,
            'reply_message' => 'No user data prompt',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_forum_ai_pending', (object) [
            'discussionid' => $discussion->id,
            'forumid' => $forum->id,
            'parentpostid' => $discussion->firstpost,
            'creator_userid' => $student->id,
            'subject' => 'Re: ' . $discussion->name,
            'message' => '<p>Personal AI response</p>',
            'status' => 'pending',
            'approval_token' => md5(uniqid('nouserdata_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $backupcontroller = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupcontroller->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $backupcontroller->get_plan()->get_setting('users')->set_value(false);
        $backupid = $backupcontroller->get_backupid();
        $backupcontroller->execute_plan();
        $backupcontroller->destroy();

        $newcourseid = \restore_dbops::create_new_course(
            'Restored without users',
            'RESTOREDNOUSERS' . $course->id,
            $course->category
        );
        $restorecontroller = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $restorecontroller->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $restorecontroller->get_plan()->get_setting('users')->set_value(false);
        $this->assertTrue($restorecontroller->execute_precheck());
        $restorecontroller->execute_plan();
        $restorecontroller->destroy();

        $restoredforum = $DB->get_record(
            'forum',
            ['course' => $newcourseid, 'name' => 'No user data forum'],
            '*',
            MUST_EXIST
        );

        // The forum configuration is course data and still travels.
        $restoredconfig = $DB->get_record(
            'local_forum_ai_config',
            ['forumid' => $restoredforum->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame('No user data prompt', $restoredconfig->reply_message);

        // The grader is a reference to a person, so it does not survive a backup
        // that carries no user data. Without it the forum falls back to approval
        // mode instead of publishing on behalf of an arbitrary user.
        $this->assertNull($restoredconfig->graderid);

        // The per-student responses do not travel either.
        $this->assertSame(
            0,
            $DB->count_records('local_forum_ai_pending', ['forumid' => $restoredforum->id])
        );
    }

    /**
     * Grader IDs are remapped to restored users and cleared when the mapping is missing.
     */
    public function test_config_graderid_is_remapped_or_cleared_when_missing_mapping(): void {
        global $DB;

        $this->resetAfterTest();
        $restore = new restore_local_forum_ai_plugin_test_double();

        $restore->seed_mappings([
            'forum' => [
                1001 => 2001,
                1002 => 2002,
            ],
            'user' => [
                3001 => 4001,
            ],
        ]);
        $restore->seed_tempconfigs([
            (object) [
                'forumid' => 1001,
                'enabled' => 1,
                'require_approval' => 1,
                'graderid' => 3001,
                'reply_message' => 'Mapped grader prompt',
                'timecreated' => 1710000000,
                'timemodified' => 1710000000,
            ],
            (object) [
                'forumid' => 1002,
                'enabled' => 1,
                'require_approval' => 1,
                'graderid' => 3002,
                'reply_message' => 'Unmapped grader prompt',
                'timecreated' => 1710000000,
                'timemodified' => 1710000000,
            ],
        ]);

        $this->expectOutputRegex('/.*/s');
        $restore->after_restore_course();

        $mappedconfig = $DB->get_record('local_forum_ai_config', ['forumid' => 2001], '*', MUST_EXIST);
        $unmappedconfig = $DB->get_record('local_forum_ai_config', ['forumid' => 2002], '*', MUST_EXIST);

        $this->assertSame(4001, (int) $mappedconfig->graderid);
        $this->assertNull($unmappedconfig->graderid);
    }

    /**
     * Existing forum AI config rows are kept intact during restore.
     */
    public function test_restore_keeps_existing_config_row_intact(): void {
        global $DB;

        $this->resetAfterTest();
        $restore = new restore_local_forum_ai_plugin_test_double();

        $restore->seed_mappings([
            'forum' => [1001 => 2001],
            'user' => [3001 => 4001],
        ]);
        $restore->seed_tempconfigs([
            (object) [
                'forumid' => 1001,
                'enabled' => 1,
                'require_approval' => 1,
                'graderid' => 3001,
                'reply_message' => 'Restored config',
                'timecreated' => 1710000000,
                'timemodified' => 1710000000,
            ],
        ]);

        $existing = (object) [
            'forumid' => 2001,
            'enabled' => 0,
            'enablediainitconversation' => 0,
            'questionturns' => 3,
            'allowedroles' => 'manual,config',
            'reply_message' => 'Manual config',
            'require_approval' => 0,
            'graderid' => 9999,
            'usedelay' => 1,
            'delayminutes' => 15,
            'replyinlocked' => 1,
            'timecreated' => 1700000000,
            'timemodified' => 1700000001,
        ];
        $DB->insert_record('local_forum_ai_config', $existing);

        $this->expectOutputRegex('/.*/s');
        $restore->after_restore_course();

        $config = $DB->get_record('local_forum_ai_config', ['forumid' => 2001], '*', MUST_EXIST);

        $this->assertSame(0, (int) $config->enabled);
        $this->assertSame('Manual config', $config->reply_message);
        $this->assertSame(0, (int) $config->require_approval);
        $this->assertSame(9999, (int) $config->graderid);
        $this->assertSame(1, $DB->count_records('local_forum_ai_config', ['forumid' => 2001]));
    }

    /**
     * Running restore twice keeps the first restored config and does not crash.
     */
    public function test_restore_can_run_twice_for_same_forum_without_duplicate_config(): void {
        global $DB;

        $this->resetAfterTest();
        $restore = new restore_local_forum_ai_plugin_test_double();

        $restore->seed_mappings([
            'forum' => [1001 => 2001],
            'user' => [3001 => 4001],
        ]);
        $restore->seed_tempconfigs([
            (object) [
                'forumid' => 1001,
                'enabled' => 1,
                'require_approval' => 1,
                'graderid' => 3001,
                'reply_message' => 'Restored config',
                'timecreated' => 1710000000,
                'timemodified' => 1710000000,
            ],
        ]);

        $this->expectOutputRegex('/.*/s');
        $restore->after_restore_course();
        $restore->after_restore_course();

        $config = $DB->get_record('local_forum_ai_config', ['forumid' => 2001], '*', MUST_EXIST);

        $this->assertSame(1, (int) $config->enabled);
        $this->assertSame('Restored config', $config->reply_message);
        $this->assertSame(1, $DB->count_records('local_forum_ai_config', ['forumid' => 2001]));
    }

    /**
     * Missing delayminutes in backup must fall back to the plugin default delay.
     */
    public function test_restore_uses_default_delay_minutes_when_field_missing(): void {
        global $DB;

        $this->resetAfterTest();
        $restore = new restore_local_forum_ai_plugin_test_double();

        $restore->seed_mappings([
            'forum' => [1001 => 2001],
        ]);
        $restore->seed_tempconfigs([
            (object) [
                'forumid' => 1001,
                'enabled' => 1,
                'require_approval' => 1,
                'reply_message' => 'Restored config',
                'timecreated' => 1710000000,
                'timemodified' => 1710000000,
            ],
        ]);

        unset_config('default_delayminutes', 'local_forum_ai');

        $this->expectOutputRegex('/.*/s');
        $restore->after_restore_course();

        $config = $DB->get_record('local_forum_ai_config', ['forumid' => 2001], '*', MUST_EXIST);

        $this->assertSame(60, (int) $config->delayminutes);
    }

    /**
     * Explicit delayminutes in backup must be preserved as restored data.
     */
    public function test_restore_preserves_explicit_delay_minutes(): void {
        global $DB;

        $this->resetAfterTest();
        $restore = new restore_local_forum_ai_plugin_test_double();

        $restore->seed_mappings([
            'forum' => [1001 => 2001],
        ]);
        $restore->seed_tempconfigs([
            (object) [
                'forumid' => 1001,
                'enabled' => 1,
                'require_approval' => 1,
                'reply_message' => 'Restored config',
                'delayminutes' => 15,
                'timecreated' => 1710000000,
                'timemodified' => 1710000000,
            ],
        ]);

        $this->expectOutputRegex('/.*/s');
        $restore->after_restore_course();

        $config = $DB->get_record('local_forum_ai_config', ['forumid' => 2001], '*', MUST_EXIST);

        $this->assertSame(15, (int) $config->delayminutes);
    }

    /**
     * Restore fallback must match the direct default delay helper.
     */
    public function test_restored_delay_matches_direct_default_delay_minutes(): void {
        global $DB;

        $this->resetAfterTest();
        $restore = new restore_local_forum_ai_plugin_test_double();

        $restore->seed_mappings([
            'forum' => [1001 => 2001],
        ]);
        $restore->seed_tempconfigs([
            (object) [
                'forumid' => 1001,
                'enabled' => 1,
                'require_approval' => 1,
                'reply_message' => 'Restored config',
                'timecreated' => 1710000000,
                'timemodified' => 1710000000,
            ],
        ]);

        set_config('default_delayminutes', 0, 'local_forum_ai');
        $this->assertSame(1, \local_forum_ai\utils::get_default_delay_minutes());

        $this->expectOutputRegex('/.*/s');
        $restore->after_restore_course();

        $config = $DB->get_record('local_forum_ai_config', ['forumid' => 2001], '*', MUST_EXIST);

        $this->assertSame(\local_forum_ai\utils::get_default_delay_minutes(), (int) $config->delayminutes);
    }
}
