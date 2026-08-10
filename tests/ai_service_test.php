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
 * Tests for the AI service response mapping.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

/**
 * Tests for \local_forum_ai\ai_service::format_chat_response().
 *
 * A missing grade in the service response must stay missing (null), never
 * become a real zero in the student's record; an explicit zero returned by
 * the service is a legitimate grade and must be preserved.
 *
 * Covers: MDL-UNIT-004 — Mapeo de la respuesta del servicio de IA
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\ai_service
 */
final class ai_service_test extends \advanced_testcase {
    /**
     * A response without a grade field must map to a null grade.
     */
    public function test_missing_grade_maps_to_null(): void {
        $result = ai_service::format_chat_response(['reply' => 'Feedback text']);

        $this->assertSame('Feedback text', $result['reply']);
        $this->assertNull($result['grade']);
    }

    /**
     * An explicit zero grade is a real grade and must be preserved.
     */
    public function test_explicit_zero_grade_is_preserved(): void {
        $result = ai_service::format_chat_response(['reply' => 'Poor work', 'grade' => 0]);

        $this->assertSame(0, $result['grade']);
    }

    /**
     * A regular grade passes through unchanged.
     */
    public function test_grade_passes_through(): void {
        $result = ai_service::format_chat_response(['reply' => 'Good work', 'grade' => 2]);

        $this->assertSame(2, $result['grade']);
    }

    /**
     * A null or malformed response must yield no reply and no grade.
     */
    public function test_empty_response_maps_to_nulls(): void {
        $result = ai_service::format_chat_response(null);

        $this->assertNull($result['reply']);
        $this->assertNull($result['grade']);
    }
}
