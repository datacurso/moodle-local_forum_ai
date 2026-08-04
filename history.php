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
 * Forum AI plugin configuration.
 *
 * @package    local_forum_ai
 * @category   admin
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');


$courseid = required_param('courseid', PARAM_INT);
$forumid  = optional_param('forumid', 0, PARAM_INT);

if ($forumid) {
    $forum = $DB->get_record('forum', ['id' => $forumid], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $forum->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

    require_login($course, true, $cm);
    $context = context_module::instance($cm->id);
} else {
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

    require_login($course);
    $context = context_course::instance($course->id);
}

require_capability('local/forum_ai:approveresponses', $context);

$PAGE->set_url('/local/forum_ai/history.php', ['forumid' => $forumid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('historyresponses', 'local_forum_ai'));
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/local/forum_ai/styles/review.css');

$courseid = $course->id;
$records = local_forum_ai_get_history($courseid, $forumid);

$statusmap = [
    'approved' => get_string('statusapproved', 'local_forum_ai'),
    'rejected' => get_string('statusrejected', 'local_forum_ai'),
    'pending'  => get_string('statuspending', 'local_forum_ai'),
];

$renderer = $PAGE->get_renderer('core');
$headerlogo = new \local_forum_ai\output\header_logo();
$logocontext = $headerlogo->export_for_template($renderer);

$templatecontext = [
    'col_course' => get_string('coursename', 'local_forum_ai'),
    'col_forum' => get_string('forumname', 'local_forum_ai'),
    'col_discussion' => get_string('discussionname', 'local_forum_ai'),
    'col_message' => get_string('discussionmsg', 'local_forum_ai'),
    'col_grade' => get_string('grade', 'local_forum_ai'),
    'col_user' => get_string('username', 'local_forum_ai'),
    'col_status' => get_string('status', 'local_forum_ai'),
    'col_actions' => get_string('actions', 'local_forum_ai'),
    'noresponses' => get_string('nohistory', 'local_forum_ai'),
    'hashistory' => !empty($records),
    'responses' => [],
    'headerlogo' => $logocontext,
];


foreach ($records as $r) {
    $user = (object)['id' => $r->creator_userid, 'firstname' => $r->firstname, 'lastname' => $r->lastname];

    $templatecontext['responses'][] = [
        'coursename' => format_string($r->coursename),
        'forumname' => format_string($r->forumname),
        'discussionname' => format_string($r->discussionname),
        'discussionmsg' => shorten_text(strip_tags($r->message), 100),
        'grade' => (isset($r->grade) && $r->grade !== '' ? format_string($r->grade) : '-'),
        'userfullname' => fullname($user),
        'status' => $statusmap[$r->status] ?? $r->status,
        'viewdetails' => get_string('viewdetails', 'local_forum_ai'),
        'token' => $r->approval_token,
    ];
}

echo $OUTPUT->header();
// Back to course button.
$backurl = new moodle_url('/course/view.php', ['id' => $course->id]);
echo html_writer::link(
    $backurl,
    get_string('backtocourse', 'local_forum_ai'),
    ['class' => 'btn btn-secondary mb-3']
);
echo $OUTPUT->render_from_template('local_forum_ai/history', $templatecontext);
$PAGE->requires->js_call_amd('local_forum_ai/history', 'init');
echo $OUTPUT->footer();
