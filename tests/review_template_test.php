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
 * Tests for the rendering of the review page template.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/xss_payload_fixture.php');

/**
 * Tests that the review template escapes the editable AI message exactly once.
 *
 * Regression pin for the double-escape bug: review.php used to pass the
 * textarea source through s(), so the single mustache escape in
 * templates/review.mustache produced double-escaped '&amp;lt;' entities.
 *
 * @group local_forum_ai
 * @coversNothing
 */
final class review_template_test extends \advanced_testcase {
    /**
     * The textarea must contain the single-escaped HTML source, never double-escaped
     * entities nor raw script markup.
     */
    public function test_review_template_escapes_editable_message_once(): void {
        global $PAGE;

        $this->resetAfterTest();

        $context = \context_system::instance();
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_forum_ai/review', [
            'aimessage' => format_text(xss_payload_fixture::PAYLOAD, FORMAT_HTML, ['context' => $context]),
            'aiformatted' => clean_text(xss_payload_fixture::PAYLOAD, FORMAT_HTML),
            'aisubject' => 'Re: Discussion A',
            'token' => 'unittesttoken0000000000000000001',
            'discussionid' => 1,
            'forumurl' => 'https://example.com/mod/forum/discuss.php?d=1',
        ]);

        // Single-escaped HTML source inside the textarea.
        $this->assertStringContainsString('&lt;p&gt;Hola', $html);
        // No double escaping (the old s() + mustache-escape bug).
        $this->assertStringNotContainsString('&amp;lt;', $html);
        // No live script markup anywhere in the rendered page.
        $this->assertStringNotContainsString('<script', $html);
    }
}
