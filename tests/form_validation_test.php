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
 * Tests for the forum AI fields validation in the forum settings form.
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
 * Tests for local_forum_ai_coursemodule_validation().
 *
 * Covers: MDL-INT-002 — automatic mode cannot be saved without a grader.
 *
 * @group local_forum_ai
 * @covers ::local_forum_ai_coursemodule_validation
 */
final class form_validation_test extends \advanced_testcase {
    /**
     * Automatic mode (no review) with AI enabled and no grader is rejected on the grader field.
     */
    public function test_automatic_mode_without_grader_is_rejected(): void {
        $this->resetAfterTest();
        set_config('default_enabled', 1, 'local_forum_ai');

        $errors = local_forum_ai_coursemodule_validation(null, [
            'local_forum_ai_enabled' => 1,
            'local_forum_ai_require_approval' => 0,
            'local_forum_ai_grader' => 0,
        ]);

        $this->assertArrayHasKey('local_forum_ai_grader', $errors);
        $this->assertSame(get_string('error_graderrequired', 'local_forum_ai'), $errors['local_forum_ai_grader']);
    }

    /**
     * Automatic mode with a grader, review mode without one, or AI disabled are all accepted.
     *
     * @dataProvider accepted_provider
     * @param array $fields Submitted form fields.
     */
    public function test_valid_combinations_are_accepted(array $fields): void {
        $this->resetAfterTest();
        set_config('default_enabled', 1, 'local_forum_ai');

        $this->assertSame([], local_forum_ai_coursemodule_validation(null, $fields));
    }

    /**
     * Valid field combinations.
     *
     * @return array
     */
    public static function accepted_provider(): array {
        return [
            'automatic with grader' => [[
                'local_forum_ai_enabled' => 1,
                'local_forum_ai_require_approval' => 0,
                'local_forum_ai_grader' => 5,
            ]],
            'review without grader' => [[
                'local_forum_ai_enabled' => 1,
                'local_forum_ai_require_approval' => 1,
                'local_forum_ai_grader' => 0,
            ]],
            'ai disabled without grader' => [[
                'local_forum_ai_enabled' => 0,
                'local_forum_ai_require_approval' => 0,
                'local_forum_ai_grader' => 0,
            ]],
            'fields not shown to the user' => [[]],
        ];
    }

    /**
     * With global AI disabled the forum fields are forced off, so nothing is validated.
     */
    public function test_global_ai_disabled_skips_validation(): void {
        $this->resetAfterTest();
        set_config('default_enabled', 0, 'local_forum_ai');

        $this->assertSame([], local_forum_ai_coursemodule_validation(null, [
            'local_forum_ai_enabled' => 1,
            'local_forum_ai_require_approval' => 0,
            'local_forum_ai_grader' => 0,
        ]));
    }
}
