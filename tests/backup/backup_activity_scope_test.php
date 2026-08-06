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
 * Tests for the activity-level backup scope of local_forum_ai.
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

/**
 * Tests that single-activity backups never carry plugin data.
 *
 * The full course-level round trip (config, pendings, states, grades and
 * remapping) is covered by tests/backup/backup_restore_test.php.
 *
 * @group local_forum_ai
 * @covers \backup_local_forum_ai_plugin::define_course_plugin_structure
 * @covers \restore_local_forum_ai_plugin::after_restore_course
 */
final class backup_activity_scope_test extends \advanced_testcase {
    /**
     * MDL-INT-026 (step 3): the backup of a single forum activity does not include
     * plugin data — restoring it creates no local_forum_ai rows for the new forum.
     */
    public function test_activity_backup_excludes_plugin_data(): void {
        global $CFG, $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $this->expectOutputRegex('/.*/s');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $forummodule = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'name' => 'Activity scope forum',
        ]);
        $forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
        ]);
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        $DB->insert_record('local_forum_ai_config', (object) [
            'forumid' => $forum->id,
            'enabled' => 1,
            'require_approval' => 1,
            'reply_message' => 'Activity scope prompt',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_forum_ai_pending', (object) [
            'discussionid' => $discussion->id,
            'forumid' => $forum->id,
            'parentpostid' => $discussion->firstpost,
            'creator_userid' => $student->id,
            'subject' => 'Re: ' . $discussion->name,
            'message' => '<p>AI reply</p>',
            'status' => 'pending',
            'approval_token' => md5(uniqid('activityscope_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $configcount = $DB->count_records('local_forum_ai_config');
        $pendingcount = $DB->count_records('local_forum_ai_pending');

        // Single-activity backup of the forum. MODE_IMPORT is the core-standard
        // mode for in-process activity backup + restore (see duplicate() in
        // backup/moodle2/tests/moodle2_test.php): it keeps the backup structure
        // on disk so the restore controller can precheck it directly, while
        // MODE_GENERAL packs and removes it (restore status 200, REQUIRE_CONV).
        $backupcontroller = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $cm->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $backupcontroller->get_backupid();
        $backupcontroller->execute_plan();
        $backupcontroller->destroy();

        // Restore the activity into a different course.
        $targetcourse = $this->getDataGenerator()->create_course();
        $restorecontroller = new \restore_controller(
            $backupid,
            $targetcourse->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_CURRENT_ADDING
        );
        $this->assertTrue($restorecontroller->execute_precheck());
        $restorecontroller->execute_plan();
        $restorecontroller->destroy();

        $restoredforum = $DB->get_record(
            'forum',
            ['course' => $targetcourse->id, 'name' => 'Activity scope forum'],
            '*',
            MUST_EXIST
        );

        // The restored forum carries no plugin data at all.
        $this->assertSame(0, $DB->count_records('local_forum_ai_config', ['forumid' => $restoredforum->id]));
        $this->assertSame(0, $DB->count_records('local_forum_ai_pending', ['forumid' => $restoredforum->id]));

        // No plugin rows were created anywhere by the activity restore.
        $this->assertSame($configcount, $DB->count_records('local_forum_ai_config'));
        $this->assertSame($pendingcount, $DB->count_records('local_forum_ai_pending'));
    }

    /**
     * MDL-INT-026 (Esperado, dev finding): restoring a config without the
     * "reply in locked discussions" field must default to the INHERIT state
     * (utils::REPLY_IN_LOCKED_INHERIT), not to an explicit "No".
     */
    public function test_restore_missing_replyinlocked_defaults_to_inherit(): void {
        $this->markTestSkipped(
            'MDL-INT-026 NOTA [Pendiente:skip]: al restaurar sin el campo "responder en ' .
            'discusiones bloqueadas", el valor cae a "No" explicito (0) en lugar del estado ' .
            '"heredar" (utils::REPLY_IN_LOCKED_INHERIT), reintroduciendo para los foros ' .
            'restaurados el bloqueo del valor global que ya se habia corregido — misma clase ' .
            'de defecto que el default del tiempo de espera.'
        );
    }
}
