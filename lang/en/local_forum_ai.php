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

/**
 * Plugin strings are defined here.
 *
 * @package     local_forum_ai
 * @category    string
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action_failed'] = 'Action could not be processed.';
$string['actions'] = 'Actions';
$string['ai_response'] = 'AI Response';
$string['ai_response_approved'] = 'AI Response Approved';
$string['ai_response_proposed'] = 'Proposed AI Response';
$string['ai_response_rejected'] = 'AI Response Rejected';
$string['ai_review_button'] = 'Review with AI';
$string['aiproposed'] = 'Proposed AI Response';
$string['allowedroles'] = 'Allowed roles for AI responses';
$string['allowedroles_help'] = 'Select which user roles the AI is allowed to respond to. If none are selected, the AI will not respond to any users.';
$string['alreadysubmitted'] = 'This request has already been approved, rejected, or does not exist.';
$string['approve'] = 'Approve';
$string['autogradegrader'] = 'Recorded grader for auto approvals';
$string['autogradegrader_help'] = 'Select the user who will be recorded as the grader whenever AI feedback is auto approved. Only users who can grade assignments in this course are listed.';
$string['backtocourse'] = 'Back to course';
$string['backtodiscussion'] = 'Back to discussion';
$string['backup:includeai'] = 'Include AI forum data in backups';
$string['cancel'] = 'Cancel';
$string['col_message'] = 'Message';
$string['course'] = 'Course';
$string['coursename'] = 'Course';
$string['created'] = 'Created';
$string['datacurso_custom'] = 'Datacurso Forum AI';
$string['default_reply_message'] = 'Reply with an empathetic and motivational tone';
$string['delayminutes'] = 'Delay time (minutes)';
$string['delayminutes_help'] = 'Number of minutes to wait after the student posts before executing the AI review.';
$string['discussion'] = 'Discussion';
$string['discussion_label'] = 'Discussion: {$a}';
$string['discussioninfo'] = 'Discussion information';
$string['discussionmsg'] = 'AI-generated message';
$string['discussionname'] = 'Subject';
$string['defaultenableai'] = 'Enable AI';
$string['defaultenableai_desc'] = 'Controls global AI availability for forums. If disabled, AI is turned off for all existing forums and cannot be enabled per forum until it is enabled again globally.';
$string['enableforumai'] = 'Enable Forum AI';
$string['enableforumai_desc'] = 'If disabled, the "Datacurso Forum AI" section is hidden from forum activity settings and automatic processing is paused.';
$string['enabled'] = 'Enable AI';
$string['enablediainitconversation'] = 'Enable AI response to the discussion topic';
$string['enablediainitconversation_help'] = 'Enabling this option will allow the AI ​​to respond to the initial message that starts the discussion. It is also recommended to select the Teacher role in the following field.';
$string['error_airequest'] = 'Error communicating with AI service: {$a}';
$string['evaluatingwithai'] = 'Evaluating with AI...';
$string['forum'] = 'Forum';
$string['forum_ai:approveresponses'] = 'Approve or reject AI-generated forum responses';
$string['forum_ai:useaireview'] = 'Use AI review functionality for forum grading';
$string['forumname'] = 'Forum';
$string['grade'] = 'Grade';
$string['gradesappliedsuccessfully'] = 'Grades applied successfully by AI';
$string['historyresponses'] = 'Forum AI Response History';
$string['invalidaction'] = 'The specified action is invalid.';
$string['level'] = 'Level: {$a}';
$string['messageprovider:ai_approval_request'] = 'AI Approval Request';
$string['modal_title'] = 'Discussion History Details';
$string['modal_title_pending'] = 'Discussion Details';
$string['no'] = 'No';
$string['no_posts'] = 'No posts found in this discussion.';
$string['nohistory'] = 'No approved or rejected AI responses found.';
$string['noresponses'] = 'No pending responses for approval.';
$string['notification_course_label'] = 'Course';
$string['notification_fullmessage'] = 'Hello {$a->firstname},

An AI-generated response has been created for the discussion "{$a->discussion}" in the forum "{$a->forum}" (Course: {$a->course}).

Preview: {$a->preview}...

To review the complete message and decide whether to approve or reject it, please visit:
{$a->reviewurl}

Quick actions:
- Approve: {$a->approveurl}
- Reject: {$a->rejecturl}';
$string['notification_greeting'] = 'Hello {$a->firstname},';
$string['notification_intro'] = 'An automatic response has been generated for the discussion "{$a->discussion}" in the forum "{$a->forum}" of the course "{$a->course}".';
$string['notification_preview'] = 'Preview:';
$string['notification_review_button'] = 'Review Response';
$string['notification_smallmessage'] = 'New pending AI response in "{$a->discussion}"';
$string['notification_subject'] = 'Approval Required: AI Response';
$string['originalmessage'] = 'Original message';
$string['pendingresponses'] = 'Pending Forum AI Responses';
$string['pluginname'] = 'Forum AI';
$string['preview'] = 'AI Message';
$string['questionturns'] = 'AI replies with guiding question (per thread)';
$string['questionturns_help'] = 'Choose how many AI responses in the same reply thread should include both feedback and a guiding question. After reaching this limit, AI will continue with feedback only. Use 0 to disable guiding questions.';
$string['privacy:metadata:local_forum_ai_config'] = 'Stores AI configurations per forum.';
$string['privacy:metadata:local_forum_ai_config:enabled'] = 'Indicates whether AI is enabled for this forum.';
$string['privacy:metadata:local_forum_ai_config:forumid'] = 'The ID of the forum this configuration belongs to.';
$string['privacy:metadata:local_forum_ai_config:questionturns'] = 'Maximum number of AI replies with guiding questions allowed per reply thread.';
$string['privacy:metadata:local_forum_ai_config:reply_message'] = 'Reply template generated by the AI.';
$string['privacy:metadata:local_forum_ai_config:require_approval'] = 'Indicates whether AI responses require approval before being published.';
$string['privacy:metadata:local_forum_ai_config:timecreated'] = 'Date when the configuration was created.';
$string['privacy:metadata:local_forum_ai_config:timemodified'] = 'Date when the configuration was last modified.';
$string['privacy:metadata:local_forum_ai_pending'] = 'Data stored by the Forum AI plugin.';
$string['privacy:metadata:local_forum_ai_pending:approval_token'] = 'Approval token linked to the post.';
$string['privacy:metadata:local_forum_ai_pending:approved_at'] = 'Date when the response was approved.';
$string['privacy:metadata:local_forum_ai_pending:creator_userid'] = 'ID of the user who created the post.';
$string['privacy:metadata:local_forum_ai_pending:discussionid'] = 'ID of the related discussion.';
$string['privacy:metadata:local_forum_ai_pending:forumid'] = 'ID of the forum where the response was generated.';
$string['privacy:metadata:local_forum_ai_pending:message'] = 'Message generated by artificial intelligence.';
$string['privacy:metadata:local_forum_ai_pending:status'] = 'Post status (approved, pending, or rejected).';
$string['privacy:metadata:local_forum_ai_pending:subject'] = 'Subject or topic of the message.';
$string['privacy:metadata:local_forum_ai_pending:timecreated'] = 'Date when the record was created.';
$string['privacy:metadata:local_forum_ai_pending:timemodified'] = 'Date when the record was updated.';
$string['reject'] = 'Reject';
$string['reply_message'] = 'Give instructions to the AI';
$string['replylevel'] = 'Reply level {$a}';
$string['require_approval'] = 'Review AI Response';
$string['response_approved'] = 'AI response approved and published successfully.';
$string['response_rejected'] = 'AI response rejected.';
$string['response_update_failed'] = 'Failed to update response.';
$string['response_updated'] = 'Response updated successfully.';
$string['reviewtitle'] = 'Review AI Response';
$string['save'] = 'Save';
$string['saveapprove'] = 'Save and Approve';
$string['settings'] = 'Settings for: ';
$string['status'] = 'Status';
$string['statusapproved'] = 'Approved';
$string['statuspending'] = 'Pending';
$string['statusrejected'] = 'Rejected';
$string['task_process_ai_queue'] = 'Process delayed Forum AI queue';
$string['task_process_single_forum_discussion'] = 'Process single forum discussion for AI';
$string['usedelay'] = 'Use delayed review';
$string['usedelay_help'] = 'If enabled, AI review will be executed after a configurable delay instead of immediately.';
$string['username'] = 'Creator';
$string['viewdetails'] = 'Details';
$string['yes'] = 'Yes';
