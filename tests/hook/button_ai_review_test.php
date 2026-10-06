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
 * Tests for the hook that injects the AI review button.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai\hook;

use core\hook\output\before_footer_html_generation;

/**
 * Tests for \local_forum_ai\hook\button_ai_review.
 *
 * The callback is invoked directly with a forum view page set up on $PAGE,
 * and the collected footer HTML is inspected for the button.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\hook\button_ai_review
 */
final class button_ai_review_test extends \advanced_testcase {
    /**
     * A teacher with the review capability gets the button on the forum view page.
     */
    public function test_button_injected_for_teacher_when_ai_enabled(): void {
        $this->resetAfterTest();

        [$cm, $teacher] = $this->create_forum_with_teacher();
        $this->setUser($teacher);

        $this->assertStringContainsString('forum-ai-review-btn', $this->run_hook($cm));
    }

    /**
     * The button must not be injected while either AI switch is off.
     *
     * @dataProvider disabled_switch_provider
     * @param string $switch Config name of the switch turned off.
     */
    public function test_button_not_injected_when_ai_disabled(string $switch): void {
        $this->resetAfterTest();
        set_config($switch, 0, 'local_forum_ai');

        [$cm, $teacher] = $this->create_forum_with_teacher();
        $this->setUser($teacher);

        $this->assertSame('', $this->run_hook($cm));
    }

    /**
     * A capability prohibited on the forum itself must hide the button there.
     */
    public function test_button_not_injected_without_module_capability(): void {
        global $DB;

        $this->resetAfterTest();

        [$cm, $teacher] = $this->create_forum_with_teacher();

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('local/forum_ai:useaireview', CAP_PROHIBIT, $roleid, \context_module::instance($cm->id), true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($teacher);

        $this->assertSame('', $this->run_hook($cm));
    }

    /**
     * Each AI switch that must hide the button on its own.
     *
     * @return array
     */
    public static function disabled_switch_provider(): array {
        return [
            'forum ai disabled' => ['enableforumai'],
            'global ai disabled' => ['default_enabled'],
        ];
    }

    /**
     * Sets up $PAGE as the forum view page and runs the hook callback.
     *
     * @param \stdClass $cm Forum course module.
     * @return string HTML collected by the hook.
     */
    private function run_hook(\stdClass $cm): string {
        global $PAGE;

        $PAGE->set_url('/mod/forum/view.php', ['id' => $cm->id]);
        $PAGE->set_cm(get_fast_modinfo($cm->course)->get_cm($cm->id));

        $hook = new before_footer_html_generation($PAGE->get_renderer('core'));
        button_ai_review::before_footer_html_generation($hook);

        return $hook->get_output();
    }

    /**
     * Creates a course with a forum and an editing teacher.
     *
     * The editingteacher archetype holds local/forum_ai:useaireview by default.
     *
     * @return array [$cm, $teacher].
     */
    private function create_forum_with_teacher(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

        return [$cm, $teacher];
    }
}
