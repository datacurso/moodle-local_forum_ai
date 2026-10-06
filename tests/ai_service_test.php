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
 * Tests for the AI service switch gate and response mapping.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_ai_client.php');

/**
 * Tests for \local_forum_ai\ai_service.
 *
 * The service refuses every call while an AI switch is off. A missing grade
 * in the service response must stay missing (null), never become a real
 * zero in the student's record; an explicit zero returned by the service is
 * a legitimate grade and must be preserved.
 *
 * Covers: MDL-UNIT-004 — Mapeo de la respuesta del servicio de IA
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\ai_service
 */
final class ai_service_test extends \advanced_testcase {
    /**
     * Always restore the real AI client after each test.
     */
    protected function tearDown(): void {
        ai_service::set_client_for_testing(null);
        parent::tearDown();
    }

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

    /**
     * Both service calls must refuse to transfer data while either AI switch is off.
     *
     * @dataProvider disabled_switch_provider
     * @param string $switch Config name of the switch turned off.
     */
    public function test_calls_refused_when_ai_disabled(string $switch): void {
        $this->resetAfterTest();
        set_config($switch, 0, 'local_forum_ai');

        $client = new mock_ai_client();
        ai_service::set_client_for_testing($client);

        $calls = [
            'call_ai_service' => static fn() => ai_service::call_ai_service(['message' => 'Hi']),
            'call_ai_service_global' => static fn() => ai_service::call_ai_service_global(['message' => 'Hi']),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("{$name}() was expected to be refused.");
            } catch (\moodle_exception $e) {
                $this->assertSame('error_aidisabled', $e->errorcode, $name);
            }
        }

        $this->assertSame([], $client->requests);
    }

    /**
     * Each AI switch that must block the service calls on its own.
     *
     * @return array
     */
    public static function disabled_switch_provider(): array {
        return [
            'forum ai disabled' => ['enableforumai'],
            'global ai disabled' => ['default_enabled'],
        ];
    }
}
