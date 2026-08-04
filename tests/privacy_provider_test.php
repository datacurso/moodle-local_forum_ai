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
 * Privacy provider behaviour tests for local_forum_ai (module-context model).
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

use context_module;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_forum_ai\external\approve_response;
use local_forum_ai\privacy\provider;

/**
 * Behavioural tests for the privacy provider under the module-context model.
 *
 * @coversDefaultClass \local_forum_ai\privacy\provider
 * @group local_forum_ai
 * @group local_forum_ai_privacy
 */
final class privacy_provider_test extends provider_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $student;
    /** @var \stdClass */
    private $teacher;
    /** @var \stdClass forum instance */
    private $forum;
    /** @var \stdClass */
    private $discussion;
    /** @var \cm_info */
    private $cm;

    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        [, $this->cm] = get_course_and_cm_from_instance($this->forum->id, 'forum');

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $this->course->id,
            'forum' => $this->forum->id,
            'userid' => $this->student->id,
        ]);
        $this->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);
    }

    /**
     * Inserts a pending record created by the student in this forum.
     *
     * @return int
     */
    private function create_pending(): int {
        global $DB;

        return $DB->insert_record('local_forum_ai_pending', (object) [
            'discussionid' => $this->discussion->id,
            'forumid' => $this->forum->id,
            'parentpostid' => $this->discussion->firstpost,
            'creator_userid' => $this->student->id,
            'subject' => 'Re: ' . $this->discussion->name,
            'message' => 'AI-generated reply under review.',
            'status' => 'pending',
            'approval_token' => md5(uniqid('privacy_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Upserts the config row for this forum with the teacher as grader.
     *
     * @return void
     */
    private function create_config(): void {
        global $DB;

        $config = $DB->get_record('local_forum_ai_config', ['forumid' => $this->forum->id]) ?: new \stdClass();
        $config->forumid = $this->forum->id;
        $config->enabled = 1;
        $config->require_approval = 0;
        $config->graderid = $this->teacher->id;
        $config->timemodified = time();

        if (empty($config->id)) {
            $config->timecreated = time();
            $DB->insert_record('local_forum_ai_config', $config);
        } else {
            $DB->update_record('local_forum_ai_config', $config);
        }
    }

    /**
     * Inserts a queue row whose payload post is authored by the student.
     *
     * @return int
     */
    private function create_queue(): int {
        global $DB;

        return $DB->insert_record('local_forum_ai_queue', (object) [
            'type' => 'post',
            'payload' => json_encode([
                'postid' => (int) $this->discussion->firstpost,
                'cmid' => (int) $this->cm->id,
            ]),
            'timecreated' => time(),
            'timetoprocess' => time(),
            'processed' => 0,
        ]);
    }

    /**
     * The student's pending data resolves to the forum module context.
     *
     * @covers ::get_contexts_for_userid
     */
    public function test_get_contexts_for_student(): void {
        $this->create_pending();
        $modulecontext = context_module::instance($this->cm->id);

        $contextlist = provider::get_contexts_for_userid($this->student->id);

        $this->assertEqualsCanonicalizing(
            [$modulecontext->id],
            $contextlist->get_contextids()
        );
    }

    /**
     * A user without plugin data resolves to no contexts at all.
     *
     * @covers ::get_contexts_for_userid
     */
    public function test_get_contexts_for_user_without_data(): void {
        $stranger = $this->getDataGenerator()->create_user();

        $contextlist = provider::get_contexts_for_userid($stranger->id);

        $this->assertEmpty($contextlist->get_contextids());
    }

    /**
     * The student keeps being found after a teacher approves the response.
     *
     * The approval flow overwrites pending.creator_userid with the approving
     * teacher, so attribution must also follow the parent post's author.
     *
     * @covers ::get_contexts_for_userid
     * @covers ::get_users_in_context
     * @covers ::export_user_data
     * @covers ::delete_data_for_user
     */
    public function test_student_attribution_survives_approval(): void {
        global $DB;

        $pendingid = $this->create_pending();
        $pending = $DB->get_record('local_forum_ai_pending', ['id' => $pendingid], '*', MUST_EXIST);
        $modulecontext = context_module::instance($this->cm->id);

        // Approve as the teacher: creator_userid now points to the teacher.
        $this->setUser($this->teacher);
        approve_response::execute($pending->approval_token, 'approve');
        $this->assertEquals(
            $this->teacher->id,
            $DB->get_field('local_forum_ai_pending', 'creator_userid', ['id' => $pendingid], MUST_EXIST)
        );
        $this->setAdminUser();

        // The student must still resolve to the module context.
        $contextlist = provider::get_contexts_for_userid($this->student->id);
        $this->assertContains((int) $modulecontext->id, array_map('intval', $contextlist->get_contextids()));

        // The student must still be listed in the context.
        $userlist = new userlist($modulecontext, 'local_forum_ai');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $this->student->id, array_map('intval', $userlist->get_userids()));

        // The student's export must still include the row.
        $this->export_context_data_for_user($this->student->id, $modulecontext, 'local_forum_ai');
        $data = writer::with_context($modulecontext)->get_data(
            [get_string('privacy:metadata:local_forum_ai_pending', 'local_forum_ai')]
        );
        $this->assertCount(1, $data->entries);

        // The student's erasure must still remove the row.
        $studentlist = new approved_contextlist($this->student, 'local_forum_ai', [$modulecontext->id]);
        provider::delete_data_for_user($studentlist);
        $this->assertFalse($DB->record_exists('local_forum_ai_pending', ['id' => $pendingid]));
    }

    /**
     * The grader recorded in config resolves to the forum module context.
     *
     * @covers ::get_contexts_for_userid
     */
    public function test_get_contexts_for_grader(): void {
        $this->create_config();
        $modulecontext = context_module::instance($this->cm->id);

        $contextlist = provider::get_contexts_for_userid($this->teacher->id);

        $this->assertContains((int) $modulecontext->id, array_map('intval', $contextlist->get_contextids()));
    }

    /**
     * A queue row attributed to the student through its payload resolves to the module context.
     *
     * @covers ::get_contexts_for_userid
     */
    public function test_get_contexts_for_queue_user(): void {
        $this->create_queue();
        $modulecontext = context_module::instance($this->cm->id);

        $contextlist = provider::get_contexts_for_userid($this->student->id);

        $this->assertContains((int) $modulecontext->id, array_map('intval', $contextlist->get_contextids()));
    }

    /**
     * A discussion-type queue row is attributed to the discussion starter.
     *
     * @covers ::get_contexts_for_userid
     */
    public function test_get_contexts_for_discussion_queue_user(): void {
        global $DB;

        $DB->insert_record('local_forum_ai_queue', (object) [
            'type' => 'discussion',
            'payload' => json_encode([
                'discussionid' => (int) $this->discussion->id,
                'cmid' => (int) $this->cm->id,
            ]),
            'timecreated' => time(),
            'timetoprocess' => time(),
            'processed' => 0,
        ]);
        $modulecontext = context_module::instance($this->cm->id);

        $contextlist = provider::get_contexts_for_userid($this->student->id);

        $this->assertContains((int) $modulecontext->id, array_map('intval', $contextlist->get_contextids()));
    }

    /**
     * Contexts without plugin data yield an empty user list.
     *
     * @covers ::get_users_in_context
     */
    public function test_get_users_in_context_empty(): void {
        // A fresh forum without any plugin data.
        $emptyforum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        [, $emptycm] = get_course_and_cm_from_instance($emptyforum->id, 'forum');

        $userlist = new userlist(context_module::instance($emptycm->id), 'local_forum_ai');
        provider::get_users_in_context($userlist);
        $this->assertCount(0, $userlist);

        // Non-module contexts are ignored entirely.
        $systemlist = new userlist(\context_system::instance(), 'local_forum_ai');
        provider::get_users_in_context($systemlist);
        $this->assertCount(0, $systemlist);
    }

    /**
     * The module context lists the student (pending, queue) and the grader (config).
     *
     * @covers ::get_users_in_context
     */
    public function test_get_users_in_context(): void {
        $this->create_pending();
        $this->create_config();
        $this->create_queue();
        $modulecontext = context_module::instance($this->cm->id);

        $userlist = new userlist($modulecontext, 'local_forum_ai');
        provider::get_users_in_context($userlist);

        $ids = array_map('intval', $userlist->get_userids());
        $this->assertContains((int) $this->student->id, $ids);
        $this->assertContains((int) $this->teacher->id, $ids);
    }

    /**
     * Export writes one document per context containing all the user's rows.
     *
     * @covers ::export_user_data
     */
    public function test_export_user_data(): void {
        $this->create_pending();
        $this->create_pending();
        $modulecontext = context_module::instance($this->cm->id);

        $this->export_context_data_for_user($this->student->id, $modulecontext, 'local_forum_ai');

        $writer = writer::with_context($modulecontext);
        $this->assertTrue($writer->has_any_data());

        // Both rows must be present: one export per context, never one per row.
        $data = $writer->get_data([get_string('privacy:metadata:local_forum_ai_pending', 'local_forum_ai')]);
        $this->assertCount(2, $data->entries);
    }

    /**
     * Deleting all data in a module context clears pending and queue and nulls the grader.
     *
     * @covers ::delete_data_for_all_users_in_context
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $this->create_pending();
        $this->create_config();
        $this->create_queue();

        // A second, unrelated forum that must remain untouched.
        $otherforum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        $DB->insert_record('local_forum_ai_pending', (object) [
            'discussionid' => $this->discussion->id,
            'forumid' => $otherforum->id,
            'creator_userid' => $this->student->id,
            'subject' => 'Other forum',
            'message' => 'Other forum pending row.',
            'status' => 'pending',
            'approval_token' => md5(uniqid('other_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        provider::delete_data_for_all_users_in_context(context_module::instance($this->cm->id));

        $this->assertEquals(0, $DB->count_records('local_forum_ai_pending', ['forumid' => $this->forum->id]));
        $this->assertEquals(0, $DB->count_records('local_forum_ai_queue'));

        // The config row survives with the grader reference anonymised.
        $config = $DB->get_record('local_forum_ai_config', ['forumid' => $this->forum->id], '*', MUST_EXIST);
        $this->assertNull($config->graderid);

        // The other forum's record survives.
        $this->assertEquals(1, $DB->count_records('local_forum_ai_pending', ['forumid' => $otherforum->id]));
    }

    /**
     * Deleting a student removes their pending and queue rows; a grader is anonymised.
     *
     * @covers ::delete_data_for_user
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $pendingid = $this->create_pending();
        $this->create_config();
        $this->create_queue();
        $modulecontext = context_module::instance($this->cm->id);

        // Data in another forum for another user must survive.
        $otherforum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        $otherpendingid = $DB->insert_record('local_forum_ai_pending', (object) [
            'discussionid' => $this->discussion->id,
            'forumid' => $otherforum->id,
            'creator_userid' => $this->teacher->id,
            'subject' => 'Other forum',
            'message' => 'Other forum pending row.',
            'status' => 'pending',
            'approval_token' => md5(uniqid('other_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Delete the student: pending and queue rows go away.
        $studentlist = new approved_contextlist($this->student, 'local_forum_ai', [$modulecontext->id]);
        provider::delete_data_for_user($studentlist);
        $this->assertFalse($DB->record_exists('local_forum_ai_pending', ['id' => $pendingid]));
        $this->assertEquals(0, $DB->count_records('local_forum_ai_queue'));

        // Delete the teacher: config grader reference is nulled, config row kept.
        $teacherlist = new approved_contextlist($this->teacher, 'local_forum_ai', [$modulecontext->id]);
        provider::delete_data_for_user($teacherlist);
        $config = $DB->get_record('local_forum_ai_config', ['forumid' => $this->forum->id], '*', MUST_EXIST);
        $this->assertNull($config->graderid);

        // Unrelated data survives.
        $this->assertTrue($DB->record_exists('local_forum_ai_pending', ['id' => $otherpendingid]));
    }

    /**
     * Deleting a set of users honours the approved userid list.
     *
     * @covers ::delete_data_for_users
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $pendingid = $this->create_pending();

        // A second student with their own pending row in the same forum.
        $otherstudent = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $otherpendingid = $DB->insert_record('local_forum_ai_pending', (object) [
            'discussionid' => $this->discussion->id,
            'forumid' => $this->forum->id,
            'creator_userid' => $otherstudent->id,
            'subject' => 'Re: ' . $this->discussion->name,
            'message' => 'Second pending row.',
            'status' => 'pending',
            'approval_token' => md5(uniqid('second_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $modulecontext = context_module::instance($this->cm->id);
        $approved = new approved_userlist($modulecontext, 'local_forum_ai', [$this->student->id]);
        provider::delete_data_for_users($approved);

        // Only the approved user's data is removed.
        $this->assertFalse($DB->record_exists('local_forum_ai_pending', ['id' => $pendingid]));
        $this->assertTrue($DB->record_exists('local_forum_ai_pending', ['id' => $otherpendingid]));
    }
}
