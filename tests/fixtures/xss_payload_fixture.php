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
 * Shared fixture with the canonical XSS payload for sanitization tests.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Canonical malicious payload shared by every sanitization test.
 *
 * A trait cannot hold constants on PHP 8.1, so a fixture class is used;
 * test files load it explicitly with require_once, which works both when
 * a single test file runs and when the whole component suite runs.
 */
final class xss_payload_fixture {
    /** @var string Malicious payload with legitimate formatting mixed in. */
    public const PAYLOAD = '<p>Hola <strong>mundo</strong></p><ul><li>a</li></ul>'
        . '<script>alert(1)</script><img src=x onerror="alert(2)">';
}
