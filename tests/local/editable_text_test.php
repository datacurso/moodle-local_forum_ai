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

namespace local_forum_ai\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/xss_payload_fixture.php');

use local_forum_ai\xss_payload_fixture;

/**
 * Tests for the HTML <-> plain text conversion of the editable AI response.
 *
 * Covers: MDL-INT-019 — Pagina de respuestas pendientes de aprobacion (editable box without raw HTML)
 * Covers: MDL-INT-025 — Sanitizacion del contenido de la IA almacenado y publicado
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group     local_forum_ai
 * @covers    \local_forum_ai\local\editable_text
 */
final class editable_text_test extends \advanced_testcase {
    /**
     * Paragraphs, line breaks and list items become plain text without tags.
     */
    public function test_from_html_removes_tags_and_keeps_paragraphs(): void {
        $this->assertSame("Hola\n\nMundo", editable_text::from_html('<p>Hola</p><p>Mundo</p>'));
        $this->assertSame("Line one\nLine two", editable_text::from_html('<p>Line one<br>Line two</p>'));
        $this->assertSame(
            "Intro\n\n- a\n- b\n\nEnd",
            editable_text::from_html('<p>Intro</p><ul><li>a</li><li>b</li></ul><p>End</p>')
        );
        $this->assertSame('Hola mundo', editable_text::from_html('<p>Hola <strong>mundo</strong></p>'));
    }

    /**
     * HTML entities are decoded so the teacher sees the real characters.
     */
    public function test_from_html_decodes_entities(): void {
        $this->assertSame('5 < 7 & listo', editable_text::from_html('<p>5 &lt; 7 &amp; listo</p>'));
    }

    /**
     * A legacy dirty row never leaks script contents or handlers into the editable text.
     */
    public function test_from_html_drops_script_content(): void {
        $text = editable_text::from_html(xss_payload_fixture::PAYLOAD);

        $this->assertStringNotContainsString('alert', $text);
        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString('Hola mundo', $text);
    }

    /**
     * Typed markup is stored as escaped text, never as live tags.
     */
    public function test_to_html_escapes_markup(): void {
        $html = editable_text::to_html('<script>x</script> <b>bold</b>');

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    /**
     * Blank lines split paragraphs and single line breaks become <br>.
     */
    public function test_to_html_builds_paragraphs(): void {
        $this->assertSame('<p>5 &lt; 7</p><p>Otro</p>', editable_text::to_html("5 < 7\r\n\r\nOtro"));
        $this->assertSame('<p>a<br />b</p>', editable_text::to_html("a\nb"));
        $this->assertSame('', editable_text::to_html("  \n\n "));
    }

    /**
     * Data provider for the round-trip stability test.
     *
     * @return array
     */
    public static function round_trip_provider(): array {
        return [
            'single line' => ['Hello world'],
            'paragraphs' => ["First paragraph\n\nSecond paragraph"],
            'line breaks' => ["Line one\nLine two\n\nThird"],
            'markup typed as text' => ['<b>Negrita permitida</b> <script>alert(1)</script>'],
            'entities and quotes' => ['5 < 7 & "quoted" \'single\''],
            'list like text' => ["Steps:\n- one\n- two"],
            'extra blank lines' => ["A\n\n\n\nB\n"],
        ];
    }

    /**
     * Converting to HTML, back to text and to HTML again is stable.
     *
     * @dataProvider round_trip_provider
     * @param string $text Plain text typed by the teacher.
     */
    public function test_round_trip_is_stable(string $text): void {
        $html = editable_text::to_html($text);

        $this->assertSame($html, editable_text::to_html(editable_text::from_html($html)));
    }
}
