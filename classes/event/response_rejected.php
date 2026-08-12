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

namespace local_forum_ai\event;

/**
 * Event triggered when a pending AI response is rejected.
 *
 * It audits every manual rejection of an AI-generated forum reply,
 * recording who rejected the response and whose post originated it.
 *
 * @package     local_forum_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class response_rejected extends \core\event\base {
    /**
     * Initialises the event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_forum_ai_pending';
    }

    /**
     * Returns the localised name of the event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventresponserejected', 'local_forum_ai');
    }

    /**
     * Returns the description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' rejected the pending AI response with id '$this->objectid' " .
            "originated by the post of the user with id '$this->relateduserid'.";
    }

    /**
     * Returns the URL of the forum the response was rejected in.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/forum/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Validates the event data.
     *
     * @return void
     * @throws \coding_exception When a required field is missing.
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->objectid)) {
            throw new \coding_exception('The \'objectid\' must be set.');
        }

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }
}
