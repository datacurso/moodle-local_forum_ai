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

// NOTE: no MOODLE_INTERNAL check since this file is required by Behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat steps and page resolvers for local_forum_ai.
 *
 * @package     local_forum_ai
 * @category    test
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_forum_ai extends behat_base {
    /**
     * Convert page names to URLs for steps like "When I am on the "X" "local_forum_ai > Y" page".
     *
     * Recognised page types:
     *  - "pending": the pending responses page scoped to a forum. Identifier: forum idnumber.
     *  - "history": the response history page scoped to a forum. Identifier: forum idnumber.
     *  - "course pending": the pending responses page for a whole course. Identifier: course shortname.
     *  - "course history": the response history page for a whole course. Identifier: course shortname.
     *  - "review": the token review page. Identifier: the approval token.
     *
     * @param string $type Identifies the page type.
     * @param string $identifier Identifies the particular page.
     * @return moodle_url The page URL.
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        switch (strtolower($type)) {
            case 'pending':
                $forum = $this->get_forum_by_idnumber($identifier);
                return new moodle_url('/local/forum_ai/pending.php', [
                    'courseid' => $forum->course,
                    'forumid' => $forum->id,
                ]);

            case 'history':
                $forum = $this->get_forum_by_idnumber($identifier);
                return new moodle_url('/local/forum_ai/history.php', [
                    'courseid' => $forum->course,
                    'forumid' => $forum->id,
                ]);

            case 'course pending':
                return new moodle_url('/local/forum_ai/pending.php', [
                    'courseid' => $this->get_course_id_by_shortname($identifier),
                ]);

            case 'course history':
                return new moodle_url('/local/forum_ai/history.php', [
                    'courseid' => $this->get_course_id_by_shortname($identifier),
                ]);

            case 'review':
                return new moodle_url('/local/forum_ai/review.php', ['token' => $identifier]);

            default:
                throw new Exception('Unrecognised local_forum_ai page type "' . $type . '".');
        }
    }

    /**
     * Visit the token review page expecting the access-denied error, then leave the error page.
     *
     * The final navigation to the site home is required because Moodle's Behat hooks fail any
     * step that finishes on a fatal error page, and review.php throws the standard
     * required_capability_exception (not a wrapped moodle_exception) when the user lacks the
     * local/forum_ai:approveresponses capability.
     *
     * @Then /^the review page for token "(?P<token_string>(?:[^"]|\\")*)" should deny access$/
     *
     * @param string $token The approval token of the pending response.
     */
    public function review_page_should_deny_access(string $token): void {
        $url = new moodle_url('/local/forum_ai/review.php', ['token' => $token]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));

        // Assert directly through Mink (not via execute()) so no chained exception check
        // runs while we are still on the error page.
        $expected = get_string(
            'nopermissions',
            'error',
            get_string('forum_ai:approveresponses', 'local_forum_ai')
        );
        $this->assertSession()->pageTextContains($expected);
        // The standard 403 must not be masked by the generic AI request error.
        $this->assertSession()->pageTextNotContains(get_string('error_airequest', 'local_forum_ai'));

        // Leave the error page so the automatic exception check does not flag this scenario.
        $this->getSession()->visit($this->locate_path('/'));
    }

    /**
     * Delete the forum discussion referenced by a pending response so that review.php can
     * no longer resolve its records.
     *
     * @Given /^the forum discussion of the pending response with token "(?P<token_string>(?:[^"]|\\")*)" no longer exists$/
     *
     * @param string $token The approval token of the pending response.
     */
    public function pending_response_discussion_no_longer_exists(string $token): void {
        global $DB;

        $pending = $DB->get_record('local_forum_ai_pending', ['approval_token' => $token], '*', MUST_EXIST);
        $DB->delete_records('forum_discussions', ['id' => $pending->discussionid]);
    }

    /**
     * Visit the token review page expecting the generic AI request error and assert that no
     * internal exception details are exposed, then leave the error page.
     *
     * Asserting directly through Mink (not via execute()) skips the chained exception check:
     * any Moodle error page carries div[data-rel=fatalerror] and review.php emits a
     * debugging() message before throwing, both of which would otherwise fail the step.
     * The Behat site forces debugdisplay on, so the developer debugging() notice (never shown
     * to real users) does appear on the page; the internal-detail assertions therefore target
     * the user-facing error box only. FORUMAI-SEC-006.
     *
     * @Then /^the review page for token "(?P<token_string>(?:[^"]|\\")*)" should show the generic error$/
     *
     * @param string $token The approval token of the pending response.
     */
    public function review_page_should_show_generic_error(string $token): void {
        $url = new moodle_url('/local/forum_ai/review.php', ['token' => $token]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));

        $errorbox = 'div[data-rel=fatalerror]';
        $this->assertSession()->elementTextContains('css', $errorbox, get_string('error_airequest', 'local_forum_ai'));
        $this->assertSession()->elementTextNotContains('css', $errorbox, 'forum_discussions');
        $this->assertSession()->elementTextNotContains(
            'css',
            $errorbox,
            get_string('invalidrecord', 'error', 'forum_discussions')
        );

        // Leave the error page so the automatic exception check does not flag this scenario.
        $this->getSession()->visit($this->locate_path('/'));
    }

    /**
     * Visit the token review page for an invalid or already handled token and assert
     * the informative "already submitted" notice with its continue button.
     *
     * A custom step is required because the invalid-token branch of review.php calls
     * $PAGE->set_title() without a page context (emitting a debugging() message) and
     * exits before rendering the footer, so the standard pending-JS setup never
     * completes. Core navigation steps fail on that page, either through the
     * automatic debugging() detection or through the JS-not-ready timeout. Asserting
     * directly through Mink (not via execute()) skips the chained exception check,
     * mirroring review_page_should_deny_access() below.
     *
     * @Then /^the review page for token "(?P<token_string>(?:[^"]|\\")*)" should show the already submitted notice$/
     *
     * @param string $token The approval token to visit.
     */
    public function review_page_should_show_already_submitted_notice(string $token): void {
        $url = new moodle_url('/local/forum_ai/review.php', ['token' => $token]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));

        $this->assertSession()->pageTextContains(
            get_string('alreadysubmitted', 'local_forum_ai')
        );

        // The continue button rendered by $OUTPUT->continue_button() targets /my.
        $this->find('button', get_string('continue'));

        // Leave the incomplete page so later steps and automatic checks do not flag it.
        $this->getSession()->visit($this->locate_path('/'));
    }

    /**
     * Get a forum record (id and course) from the activity idnumber.
     *
     * @param string $idnumber The activity idnumber.
     * @return stdClass Object with the forum id and course id.
     */
    protected function get_forum_by_idnumber(string $idnumber): stdClass {
        global $DB;

        $sql = "SELECT f.id, f.course
                  FROM {forum} f
                  JOIN {course_modules} cm ON cm.instance = f.id
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.idnumber = :idnumber AND m.name = 'forum'";
        $forum = $DB->get_record_sql($sql, ['idnumber' => $idnumber]);
        if (!$forum) {
            throw new Exception('There is no forum activity with idnumber "' . $idnumber . '".');
        }

        return $forum;
    }

    /**
     * Get a course id from its shortname.
     *
     * @param string $shortname The course shortname.
     * @return int The course id.
     */
    protected function get_course_id_by_shortname(string $shortname): int {
        global $DB;

        $courseid = $DB->get_field('course', 'id', ['shortname' => $shortname]);
        if (!$courseid) {
            throw new Exception('There is no course with shortname "' . $shortname . '".');
        }

        return (int) $courseid;
    }
}
