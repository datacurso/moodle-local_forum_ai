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
 * Event triggered when an AI review of a user's forum posts is requested.
 *
 * It audits every transfer of forum participation data to the external
 * AI evaluation service, recording who requested the review and whose
 * posts were sent.
 *
 * @package     local_forum_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_review_requested extends \core\event\base {
    /**
     * Initialises the event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Returns the localised name of the event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventaireviewrequested', 'local_forum_ai');
    }

    /**
     * Returns the description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' requested an AI review of the forum posts of the user " .
            "with id '$this->relateduserid'. The posts were sent to the external AI service for evaluation.";
    }

    /**
     * Returns the URL of the forum the review was requested in.
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

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }

        if (!isset($this->other['forumid'])) {
            throw new \coding_exception('The \'forumid\' value must be set in other.');
        }
    }
}
