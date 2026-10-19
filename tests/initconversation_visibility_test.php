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
 * Tests for the visibility of the skipped first-post grading.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/forum_ai/lib.php');

/**
 * With "Enable AI response to the discussion topic" off the opening post of every discussion
 * is never graded. That must not be silent: the form warns the teacher and the task reports
 * the skipped first post of graded forums (report 9).
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\task\process_ai_discussion
 * @covers ::local_forum_ai_coursemodule_standard_elements
 */
final class initconversation_visibility_test extends \advanced_testcase {
    /**
     * The task reports the skipped first post when the forum is graded and the option is off.
     */
    public function test_task_reports_skipped_first_post_of_graded_forum(): void {
        $this->resetAfterTest();
        $f = $this->create_fixture(['assessed' => 1, 'scale' => 100]);

        $this->expectOutputRegex('/skipping discussion/');
        $this->run_discussion_task($f);

        $messages = $this->getDebuggingMessages();
        $this->resetDebugging();
        $this->assertCount(1, $messages);
        $this->assertMatchesRegularExpression('/first post.*not.*graded/i', $messages[0]->message);
    }

    /**
     * A forum without grading keeps the quiet skip: there is nothing to be graded.
     */
    public function test_task_skip_stays_quiet_for_ungraded_forum(): void {
        $this->resetAfterTest();
        $f = $this->create_fixture();

        $this->expectOutputRegex('/skipping discussion/');
        $this->run_discussion_task($f);

        $this->assertDebuggingNotCalled();
    }

    /**
     * The module form shows a warning, hidden when the option is on or the forum is not graded.
     */
    public function test_form_shows_the_warning_element(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $f = $this->create_fixture();

        $mform = new \MoodleQuickForm('testform', 'post', '');
        $wrapper = new class ($f) {
            /** @var stdClass */
            private $f;

            /**
             * Constructor.
             *
             * @param stdClass $f Fixture.
             */
            public function __construct(stdClass $f) {
                $this->f = $f;
            }

            /**
             * Mimics moodleform_mod::get_current().
             *
             * @return stdClass
             */
            public function get_current(): stdClass {
                return (object) [
                    'modulename' => 'forum',
                    'instance' => $this->f->forum->id,
                    'course' => $this->f->course->id,
                ];
            }
        };

        local_forum_ai_coursemodule_standard_elements($wrapper, $mform);

        $this->assertTrue($mform->elementExists('enablediainitconversation_warning'));
        $this->assertStringContainsString(
            get_string('enablediainitconversation_warning', 'local_forum_ai'),
            $mform->getElement('enablediainitconversation_warning')->toHtml()
        );
    }

    /**
     * A NEW forum (no stored config) offers the opening-post response on by default.
     */
    public function test_new_forum_form_defaults_to_responding_to_the_first_post(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $mform = $this->build_form((int) $course->id, 0);

        $this->assertSame(1, (int) $mform->exportValue('enablediainitconversation'));
    }

    /**
     * A new forum follows the global admin default when the admin turns it off.
     */
    public function test_new_forum_form_follows_global_default_when_turned_off(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('default_enablediainitconversation', 0, 'local_forum_ai');
        $course = $this->getDataGenerator()->create_course();

        $mform = $this->build_form((int) $course->id, 0);

        $this->assertSame(0, (int) $mform->exportValue('enablediainitconversation'));
    }

    /**
     * A new forum falls back to "on" when the global default was never stored.
     */
    public function test_new_forum_form_falls_back_to_on_when_global_default_is_missing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        unset_config('default_enablediainitconversation', 'local_forum_ai');
        $course = $this->getDataGenerator()->create_course();

        $mform = $this->build_form((int) $course->id, 0);

        $this->assertSame(1, (int) $mform->exportValue('enablediainitconversation'));
    }

    /**
     * An existing forum keeps its stored value (0) regardless of the new global default.
     */
    public function test_existing_forum_form_keeps_its_stored_zero(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $f = $this->create_fixture();

        $mform = $this->build_form((int) $f->course->id, (int) $f->forum->id);

        $this->assertSame(0, (int) $mform->exportValue('enablediainitconversation'));
    }

    /**
     * Builds the module form elements for a course and forum instance (0 for a new forum).
     *
     * @param int $courseid Course id.
     * @param int $instance Forum instance id, 0 for a new forum.
     * @return \MoodleQuickForm
     */
    private function build_form(int $courseid, int $instance): \MoodleQuickForm {
        $mform = new \MoodleQuickForm('testform', 'post', '');
        $wrapper = new class ($courseid, $instance) {
            /** @var int */
            private $courseid;
            /** @var int */
            private $instance;

            /**
             * Constructor.
             *
             * @param int $courseid Course id.
             * @param int $instance Forum instance id.
             */
            public function __construct(int $courseid, int $instance) {
                $this->courseid = $courseid;
                $this->instance = $instance;
            }

            /**
             * Mimics moodleform_mod::get_current().
             *
             * @return stdClass
             */
            public function get_current(): stdClass {
                return (object) [
                    'modulename' => 'forum',
                    'instance' => $this->instance,
                    'course' => $this->courseid,
                ];
            }
        };
        local_forum_ai_coursemodule_standard_elements($wrapper, $mform);

        return $mform;
    }

    /**
     * Runs the discussion task for the fixture with the option off.
     *
     * @param stdClass $f Fixture.
     */
    private function run_discussion_task(stdClass $f): void {
        $task = new task\process_ai_discussion();
        $task->set_custom_data(['discussionid' => $f->discussion->id, 'cmid' => $f->cm->id]);
        $task->execute();
    }

    /**
     * Creates a forum with AI enabled, initial replies off, and a student discussion.
     *
     * @param array $forumoptions Extra forum options.
     * @return stdClass Holder (course, forum, cm, discussion).
     */
    private function create_fixture(array $forumoptions = []): stdClass {
        global $DB;

        $generator = $this->getDataGenerator();
        $f = new stdClass();
        $f->course = $generator->create_course();
        $student = $generator->create_and_enrol($f->course, 'student');
        $forummodule = $generator->create_module('forum', array_merge(['course' => $f->course->id], $forumoptions));
        $f->forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);
        $f->cm = get_coursemodule_from_instance('forum', $f->forum->id, $f->course->id, false, MUST_EXIST);
        $discussion = $generator->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $f->course->id,
            'forum' => $f->forum->id,
            'userid' => $student->id,
        ]);
        $f->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);
        $generator->get_plugin_generator('local_forum_ai')->create_config([
            'forumid' => $f->forum->id,
            'enabled' => 1,
            'enablediainitconversation' => 0,
        ]);

        return $f;
    }
}
