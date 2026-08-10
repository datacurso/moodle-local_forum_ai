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
 * Tests for forum AI save hooks in lib.php.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/forum_ai/lib.php');

/**
 * Tests for preserving forum AI configuration when saving forum forms.
 *
 * Covers: MDL-INT-003 — Persistencia de la configuracion del foro al guardar el formulario
 *
 * @group local_forum_ai
 * @covers \local_forum_ai_coursemodule_edit_post_actions
 */
final class lib_test extends \advanced_testcase {
    /**
     * Saving a forum without the approval capability keeps the existing AI config intact.
     */
    public function test_save_without_approval_capability_keeps_existing_config(): void {
        global $DB;

        $this->resetAfterTest();

        [$course, $forum] = $this->create_course_forum();
        $this->seed_config($forum->id, [
            'enabled' => 1,
            'require_approval' => 1,
            'reply_message' => 'Existing prompt',
            'enablediainitconversation' => 1,
            'allowedroles' => '2,3',
            'graderid' => 1234,
            'usedelay' => 1,
            'delayminutes' => 15,
            'replyinlocked' => 1,
            'questionturns' => 2,
        ]);

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $data = $this->build_form_data($course->id, $forum->id, []);
        local_forum_ai_coursemodule_edit_post_actions($data, $course);

        $config = $DB->get_record('local_forum_ai_config', ['forumid' => $forum->id], '*', MUST_EXIST);
        $this->assertSame('Existing prompt', $config->reply_message);
        $this->assertSame('2,3', $config->allowedroles);
        $this->assertSame(1234, (int) $config->graderid);
        $this->assertSame(1, (int) $config->enabled);
        $this->assertSame(1, (int) $config->require_approval);
    }

    /**
     * Saving a forum with the AI section visible persists the submitted values.
     */
    public function test_save_with_visible_section_updates_configuration_normally(): void {
        global $DB;

        $this->resetAfterTest();

        [$course, $forum] = $this->create_course_forum();
        $this->seed_config($forum->id, [
            'enabled' => 0,
            'require_approval' => 1,
            'reply_message' => 'Old prompt',
            'enablediainitconversation' => 0,
            'allowedroles' => '2',
            'graderid' => 1234,
            'usedelay' => 0,
            'delayminutes' => 60,
            'replyinlocked' => 0,
            'questionturns' => 1,
        ]);

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $data = $this->build_form_data($course->id, $forum->id, [
            'local_forum_ai_enabled' => 1,
            'local_forum_ai_require_approval' => 0,
            'local_forum_ai_reply_message' => 'New prompt',
            'enablediainitconversation' => 1,
            'allowedroles' => [2, 3],
            'local_forum_ai_grader' => 2345,
            'local_forum_ai_usedelay' => 1,
            'local_forum_ai_delayminutes' => 20,
            'local_forum_ai_replyinlocked' => 1,
            'local_forum_ai_questionturns' => 3,
        ]);

        local_forum_ai_coursemodule_edit_post_actions($data, $course);

        $config = $DB->get_record('local_forum_ai_config', ['forumid' => $forum->id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $config->enabled);
        $this->assertSame(0, (int) $config->require_approval);
        $this->assertSame('New prompt', $config->reply_message);
        $this->assertSame(1, (int) $config->enablediainitconversation);
        $this->assertSame('2,3', $config->allowedroles);
        $this->assertSame(2345, (int) $config->graderid);
        $this->assertSame(1, (int) $config->usedelay);
        $this->assertSame(20, (int) $config->delayminutes);
        $this->assertSame(1, (int) $config->replyinlocked);
        $this->assertSame(3, (int) $config->questionturns);
    }

    /**
     * Missing AI fields do not overwrite values already stored for the forum.
     */
    public function test_missing_ai_fields_do_not_overwrite_stored_values(): void {
        global $DB;

        $this->resetAfterTest();

        [$course, $forum] = $this->create_course_forum();
        $this->seed_config($forum->id, [
            'enabled' => 1,
            'require_approval' => 1,
            'reply_message' => 'Kept prompt',
            'enablediainitconversation' => 1,
            'allowedroles' => '2,4',
            'graderid' => 3456,
            'usedelay' => 1,
            'delayminutes' => 25,
            'replyinlocked' => 1,
            'questionturns' => 2,
        ]);

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $data = $this->build_form_data($course->id, $forum->id, [
            'local_forum_ai_enabled' => 0,
            'local_forum_ai_require_approval' => 0,
            'local_forum_ai_reply_message' => 'Updated prompt',
            'enablediainitconversation' => 0,
            'local_forum_ai_usedelay' => 0,
            'local_forum_ai_delayminutes' => 99,
            'local_forum_ai_replyinlocked' => 0,
            'local_forum_ai_questionturns' => 0,
        ]);

        local_forum_ai_coursemodule_edit_post_actions($data, $course);

        $config = $DB->get_record('local_forum_ai_config', ['forumid' => $forum->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $config->enabled);
        $this->assertSame(0, (int) $config->require_approval);
        $this->assertSame('Updated prompt', $config->reply_message);
        $this->assertSame(0, (int) $config->enablediainitconversation);
        $this->assertSame('2,4', $config->allowedroles);
        $this->assertSame(3456, (int) $config->graderid);
        $this->assertSame(0, (int) $config->usedelay);
        $this->assertSame(99, (int) $config->delayminutes);
        $this->assertSame(0, (int) $config->replyinlocked);
        $this->assertSame(0, (int) $config->questionturns);
    }

    /**
     * A new forum config row without an explicit reply-in-locked choice inherits the global setting.
     */
    public function test_missing_replyinlocked_value_defaults_to_inherit_global(): void {
        global $DB;

        $this->resetAfterTest();

        [$course, $forum] = $this->create_course_forum();

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $data = $this->build_form_data($course->id, $forum->id, [
            'local_forum_ai_enabled' => 1,
            'local_forum_ai_require_approval' => 1,
            'local_forum_ai_reply_message' => 'New prompt',
            'enablediainitconversation' => 0,
            'allowedroles' => [2, 3],
            'local_forum_ai_grader' => 0,
            'local_forum_ai_usedelay' => 0,
            'local_forum_ai_delayminutes' => 60,
            'local_forum_ai_questionturns' => 1,
        ]);

        local_forum_ai_coursemodule_edit_post_actions($data, $course);

        $config = $DB->get_record('local_forum_ai_config', ['forumid' => $forum->id], '*', MUST_EXIST);
        $this->assertSame(\local_forum_ai\utils::REPLY_IN_LOCKED_INHERIT, (int) $config->replyinlocked);
    }

    /**
     * Creates a course with a forum.
     *
     * @return array{0: stdClass, 1: stdClass} [$course, $forum].
     */
    private function create_course_forum(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        return [$course, $forum];
    }

    /**
     * Seeds a forum AI configuration row.
     *
     * @param int $forumid Forum id.
     * @param array $overrides Field overrides.
     * @return void
     */
    private function seed_config(int $forumid, array $overrides): void {
        global $DB;

        $config = (object) array_merge([
            'forumid' => $forumid,
            'enabled' => 1,
            'require_approval' => 1,
            'reply_message' => 'Default prompt',
            'enablediainitconversation' => 0,
            'allowedroles' => null,
            'graderid' => null,
            'usedelay' => 0,
            'delayminutes' => 60,
            'replyinlocked' => 0,
            'questionturns' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ], $overrides);

        $DB->insert_record('local_forum_ai_config', $config);
    }

    /**
     * Builds the form data object passed to the save hook.
     *
     * @param int $courseid Course id.
     * @param int $forumid Forum id.
     * @param array $overrides Field overrides.
     * @return stdClass
     */
    private function build_form_data(int $courseid, int $forumid, array $overrides): \stdClass {
        return (object) array_merge([
            'modulename' => 'forum',
            'instance' => $forumid,
            'course' => $courseid,
        ], $overrides);
    }
}
