<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_forum_ai\hook;

use core\hook\output\before_footer_html_generation;
use context_course;

/**
 * Hook responsible for injecting the AI review button into forum pages.
 *
 * The button is rendered using a Mustache template to ensure separation
 * of concerns and full compatibility with Moodle theming system.
 *
 * The button is only added:
 *  - On forum view pages.
 *  - For users with the required capability.
 *
 * @package     local_forum_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class button_ai_review {
    /**
     * Executed before the footer HTML is generated.
     *
     * Injects the AI review button and loads the required JavaScript module.
     *
     * @param before_footer_html_generation $hook Hook event instance.
     * @return void
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        global $PAGE;

        if ($PAGE->url->get_path() !== '/mod/forum/view.php') {
            return;
        }

        $coursecontext = context_course::instance($PAGE->course->id);

        if (!has_capability('local/forum_ai:useaireview', $coursecontext)) {
            return;
        }

        $PAGE->requires->js_call_amd('local_forum_ai/analyze', 'init');

        $renderer = $PAGE->get_renderer('local_forum_ai');

        $buttonhtml = $renderer->render_from_template(
            'local_forum_ai/ai_review_button',
            []
        );

        $hook->add_html($buttonhtml);
    }
}
