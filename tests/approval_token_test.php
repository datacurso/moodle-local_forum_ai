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

namespace local_forum_ai;

/**
 * Tests for the approval token factory.
 *
 * Covers: FAI-SEC-009 — Approval tokens come from a single CSPRNG factory.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_forum_ai\approval
 */
final class approval_token_test extends \basic_testcase {
    /**
     * Tokens are 64 lowercase hex characters, fit the column and are not repeated.
     */
    public function test_generate_approval_token_format_and_uniqueness(): void {
        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $token = approval::generate_approval_token();
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
            $this->assertSame($token, clean_param($token, PARAM_ALPHANUMEXT));
            $tokens[] = $token;
        }

        $this->assertCount(50, array_unique($tokens));
    }
}
