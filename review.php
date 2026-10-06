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
 * Page to review and approve or reject AI-generated responses.
 *
 * @package    local_forum_ai
 * @category   admin
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');
require_once(__DIR__ . '/locallib.php');

$token = required_param('token', PARAM_ALPHANUMEXT);

require_login();

// Token, capability and discussion group are enforced in one place. Only data
// errors are wrapped: the standard 403 (required_capability_exception) and the
// group refusal must reach the user unwrapped.
try {
    $loaded = \local_forum_ai\approval::load_pending_for_user($token, 'pending', IGNORE_MISSING);
} catch (dml_exception $e) {
    debugging('Error in review.php: ' . $e->getMessage(), DEBUG_DEVELOPER);
    // Never expose internal exception details to the user (FORUMAI-SEC-006).
    throw new moodle_exception('error_airequest', 'local_forum_ai');
}

if (!$loaded) {
    $PAGE->set_url('/local/forum_ai/review.php', ['token' => $token]);
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_title(get_string('reviewtitle', 'local_forum_ai'));
    $PAGE->set_heading(get_string('pluginname', 'local_forum_ai'));

    echo $OUTPUT->header();

    echo $OUTPUT->notification(
        get_string('alreadysubmitted', 'local_forum_ai'),
        \core\output\notification::NOTIFY_INFO
    );

    echo $OUTPUT->continue_button(new moodle_url('/my'));
    exit;
}

$pending = $loaded->pending;
$discussion = $loaded->discussion;
$forum = $loaded->forum;
$course = $loaded->course;
$cm = $loaded->cm;
$context = $loaded->context;

try {
    $originalpost = $DB->get_record('forum_posts', ['id' => $discussion->firstpost], '*', MUST_EXIST);
    $author = $DB->get_record('user', ['id' => $originalpost->userid], '*', MUST_EXIST);
} catch (Exception $e) {
    debugging('Error in review.php: ' . $e->getMessage(), DEBUG_DEVELOPER);
    // Never expose internal exception details to the user (FORUMAI-SEC-006).
    throw new moodle_exception('error_airequest', 'local_forum_ai');
}

$PAGE->set_url('/local/forum_ai/review.php', ['token' => $token]);
// Bind the course module so the navigation can initialise in a module context.
$PAGE->set_cm($cm, $course, $forum);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('reviewtitle', 'local_forum_ai'));
$PAGE->set_heading($course->fullname);

$forumurl = new moodle_url('/mod/forum/discuss.php', ['d' => $discussion->id]);

// Same deadline rule as the expiry cleanup and the publication barrier: a response
// past the forum deadline is expired here instead of being offered for review.
if (\local_forum_ai\utils::is_forum_deadline_reached($forum)) {
    local_forum_ai_expire_pending($pending);

    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('error_responseexpired', 'local_forum_ai'),
        \core\output\notification::NOTIFY_WARNING
    );
    echo $OUTPUT->continue_button($forumurl);
    echo $OUTPUT->footer();
    exit;
}

$PAGE->requires->js_call_amd('local_forum_ai/review', 'init');
$PAGE->requires->css('/local/forum_ai/styles/review.css');

$renderer = $PAGE->get_renderer('core');
$headerlogo = new \local_forum_ai\output\header_logo();
$logocontext = $headerlogo->export_for_template($renderer);

$data = [
    'course' => format_string($course->fullname),
    'forum' => format_string($forum->name),
    'discussion' => format_string($discussion->name),
    'discussionid' => $discussion->id,
    'timecreated' => userdate($pending->timecreated),
    'originalsubject' => format_string($originalpost->subject),
    'originalmessage' => format_text($originalpost->message, $originalpost->messageformat),
    'author' => fullname($author),
    'originaldate' => userdate($originalpost->created),
    'aisubject' => format_string($pending->subject),
    'aimessage' => format_text($pending->message, FORMAT_HTML),
    // Plain text for the edit box; the template escapes {{aitext}} once, so no s() here.
    // from_html() purifies first, which also neutralises legacy dirty rows.
    'aitext' => \local_forum_ai\local\editable_text::from_html($pending->message),
    'token' => $token,
    'forumurl' => $forumurl->out(),
    'headerlogo' => $logocontext,

    'strdiscussioninfo' => get_string('discussioninfo', 'local_forum_ai'),
    'strcourse' => get_string('course', 'local_forum_ai'),
    'strforum' => get_string('forum', 'local_forum_ai'),
    'strdiscussion' => get_string('discussion', 'local_forum_ai'),
    'strcreated' => get_string('created', 'local_forum_ai'),
    'stroriginalmessage' => get_string('originalmessage', 'local_forum_ai'),
    'straiproposed' => get_string('aiproposed', 'local_forum_ai'),
    'strsave' => get_string('save', 'local_forum_ai'),
    'strcancel' => get_string('cancel', 'local_forum_ai'),
    'strapprove' => get_string('approve', 'local_forum_ai'),
    'strreject' => get_string('reject', 'local_forum_ai'),
    'strbacktodiscussion' => get_string('backtodiscussion', 'local_forum_ai'),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_forum_ai/review', $data);
echo $OUTPUT->footer();
