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
 * Tests for the utils class of local_forum_ai.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

/**
 * Tests for \local_forum_ai\utils.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\utils
 */
final class utils_test extends \advanced_testcase {
    /**
     * A positive scale value (point grading) must be returned as the numeric maximum.
     */
    public function test_get_scale_payload_positive_returns_int_max(): void {
        $this->assertSame(100, utils::get_scale_payload(100));
    }

    /**
     * A negative scale id pointing to an existing scale must return its option list.
     */
    public function test_get_scale_payload_named_scale_returns_options(): void {
        $this->resetAfterTest();

        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Poor, Good, Excellent']);

        $this->assertSame(
            ['Poor', 'Good', 'Excellent'],
            utils::get_scale_payload(-$scale->id)
        );
    }

    /**
     * Options must be trimmed even when the scale string contains extra spaces.
     */
    public function test_get_scale_payload_named_scale_trims_options(): void {
        $this->resetAfterTest();

        $scale = $this->getDataGenerator()->create_scale(['scale' => ' Bad ,  Average ,   Great ']);

        $this->assertSame(
            ['Bad', 'Average', 'Great'],
            utils::get_scale_payload(-$scale->id)
        );
    }

    /**
     * A negative id with no matching scale record must return null.
     */
    public function test_get_scale_payload_missing_scale_returns_null(): void {
        $this->resetAfterTest();

        $this->assertNull(utils::get_scale_payload(-999999));
    }

    /**
     * A zero scale (grading disabled) must return null.
     */
    public function test_get_scale_payload_zero_returns_null(): void {
        $this->assertNull(utils::get_scale_payload(0));
    }
}
