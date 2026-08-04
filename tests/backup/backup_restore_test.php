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

use local_forum_ai\external\approve_response;

/**
 * Full-cycle backup/restore regression test for restored AI pending rows.
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
            'parentpostid' => $reply->id,
            'creator_userid' => $student->id,
            'subject' => 'Re: ' . $discussion->name,
            'message' => '<p>AI response to restore</p>',
            'grade' => 73,
            'status' => 'pending',
            'approval_token' => md5(uniqid('backup_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);

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
        $restoreddiscussion = $DB->get_record('forum_discussions', ['forum' => $restoredforum->id], '*', MUST_EXIST);
        $restoredreply = $DB->get_record(
            'forum_posts',
            [
                'discussion' => $restoreddiscussion->id,
                'parent' => $restoreddiscussion->firstpost,
            ],
            '*',
            MUST_EXIST
        );

        $restoredpending = $DB->get_record(
            'local_forum_ai_pending',
            ['forumid' => $restoredforum->id],
            '*',
            MUST_EXIST
        );

        $this->assertSame(73, (int) $restoredpending->grade);
        $this->assertSame((int) $restoredreply->id, (int) $restoredpending->parentpostid);

        $postcount = $DB->count_records('forum_posts', ['discussion' => $restoreddiscussion->id]);
        $result = approve_response::execute($restoredpending->approval_token, 'approve');
        $this->assertTrue($result['success']);

        $newpostid = (int) $DB->get_field(
            'local_forum_ai_pending',
            'postid',
            ['id' => $restoredpending->id],
            MUST_EXIST
        );
        $newpost = $DB->get_record('forum_posts', ['id' => $newpostid], '*', MUST_EXIST);

        $this->assertSame($postcount + 1, $DB->count_records('forum_posts', ['discussion' => $restoreddiscussion->id]));
        $this->assertSame((int) $restoredreply->id, (int) $newpost->parent);

        $rating = $DB->get_record('rating', ['itemid' => $restoredreply->id], '*', MUST_EXIST);
        $this->assertSame(73, (int) $rating->rating);
    }

    /**
     * Grader IDs are remapped to restored users and cleared when the mapping is missing.
     */
    public function test_config_graderid_is_remapped_or_cleared_when_missing_mapping(): void {
        global $DB;

        $this->resetAfterTest();
        $restore = new class extends \restore_local_forum_ai_plugin {
            /** @var array<int, array<int, int|null>> */
            private array $mappings = [];

            public function __construct() {
            }

            /**
             * @param array<int, array<int, int|null>> $mappings
             */
            public function seed_mappings(array $mappings): void {
                $this->mappings = $mappings;
            }

            /**
             * @param array<int, stdClass> $configs
             */
            public function seed_tempconfigs(array $configs): void {
                $this->tempconfigs = $configs;
            }

            protected function get_mappingid($itemname, $oldid, $ifnotfound = false) {
                return $this->mappings[$itemname][$oldid] ?? $ifnotfound;
            }
        };

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
}
