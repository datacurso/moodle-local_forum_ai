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

/**
 * Converts the AI response between stored HTML and the plain text of the edit box.
 *
 * The edit box is a plain textarea, so the teacher edits text, not markup:
 * from_html() produces that text and to_html() turns what the teacher typed
 * back into escaped, purified paragraphs. Core html_to_text() is not used
 * because it upper-cases bold text and appends link notes.
 *
 * @package    local_forum_ai
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class editable_text {
    /**
     * Converts stored HTML into plain text for the edit box.
     *
     * The HTML is purified first, so script contents of legacy dirty rows never
     * reach the text. Block ends become blank lines, <br> becomes a line break
     * and list items are prefixed with "- ".
     *
     * @param string $html Stored AI response HTML.
     * @return string Plain text with paragraphs separated by one blank line.
     */
    public static function from_html(string $html): string {
        $html = clean_text($html, FORMAT_HTML);
        $html = str_replace(["\r\n", "\r"], "\n", $html);

        $html = preg_replace('~<br\s*/?>~i', "\n", $html);
        $html = preg_replace('~<li\b[^>]*>~i', "\n- ", $html);
        // Each item starts on its own line; the list end adds the blank line after it.
        $html = preg_replace('~</li\s*>~i', '', $html);
        $html = preg_replace('~</(p|div|h[1-6]|ul|ol|blockquote|pre|table|tr)\s*>~i', "\n\n", $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::normalise($text);
    }

    /**
     * Converts plain text typed in the edit box into purified HTML paragraphs.
     *
     * Everything typed is escaped, so markup written by the teacher is stored
     * as visible text and never as live tags.
     *
     * @param string $text Plain text from the edit box.
     * @return string Purified HTML, or an empty string for blank text.
     */
    public static function to_html(string $text): string {
        $text = self::normalise($text);
        if ($text === '') {
            return '';
        }

        $paragraphs = [];
        foreach (explode("\n\n", $text) as $paragraph) {
            $lines = array_map(static fn(string $line): string => s($line), explode("\n", $paragraph));
            $paragraphs[] = '<p>' . implode('<br>', $lines) . '</p>';
        }

        return clean_text(implode('', $paragraphs), FORMAT_HTML);
    }

    /**
     * Tells whether the submitted plain text is the unchanged text of the stored HTML.
     *
     * @param string $text Plain text from the edit box.
     * @param string $html Stored AI response HTML.
     * @return bool True when saving the text would not change the response content.
     */
    public static function is_unchanged(string $text, string $html): bool {
        return self::normalise($text) === self::from_html($html);
    }

    /**
     * Normalises line endings and blank lines of plain text.
     *
     * @param string $text Plain text.
     * @return string Text with LF endings, trimmed lines, at most one blank line in a row, trimmed.
     */
    private static function normalise(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Non-breaking spaces are an editor artefact; treat them as plain spaces.
        $text = str_replace("\u{00A0}", ' ', $text);
        // HTML source indentation is not content: trim every line.
        $text = preg_replace('~^[ \t]+|[ \t]+$~m', '', $text);
        $text = preg_replace('~\n{3,}~', "\n\n", $text);

        return trim($text);
    }
}
