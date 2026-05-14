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

/**
 * Extends the settings navigation (the "More" menu in forum activities).
 *
 * @param settings_navigation $nav
 * @param context $context
 */
function local_forum_ai_extend_settings_navigation(settings_navigation $nav, context $context) {
    global $PAGE, $DB, $USER;

    if (!\local_forum_ai\utils::is_feature_enabled()) {
        return;
    }

    // Only apply in forum module contexts.
    if ($context->contextlevel != CONTEXT_MODULE || $PAGE->cm->modname !== 'forum') {
        return;
    }

    // Require capability to see AI management options.
    if (!has_capability('local/forum_ai:approveresponses', $context, $USER)) {
        return;
    }

    $forumid = $PAGE->cm->instance;
    $courseid = $PAGE->course->id;

    $urlpending = new moodle_url('/local/forum_ai/pending.php', [
        'courseid' => $courseid,
        'forumid'  => $forumid,
    ]);
    $urlhistory = new moodle_url('/local/forum_ai/history.php', [
        'courseid' => $courseid,
        'forumid'  => $forumid,
    ]);

    $modulesettings = $nav->find('modulesettings', navigation_node::TYPE_SETTING);

    if ($modulesettings) {
        $modulesettings->add(
            get_string('pendingresponses', 'local_forum_ai'),
            $urlpending,
            navigation_node::TYPE_SETTING,
            null,
            'forum_ai_pending',
            new pix_icon('i/warning', '')
        );
        $modulesettings->add(
            get_string('historyresponses', 'local_forum_ai'),
            $urlhistory,
            navigation_node::TYPE_SETTING,
            null,
            'forum_ai_history',
            new pix_icon('i/log', '')
        );
    }
}

/**
 * Extends the course navigation tree (left-hand side menu).
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param stdClass $context
 */
function local_forum_ai_extend_navigation_course($navigation, $course, $context) {
    global $USER;

    if (!\local_forum_ai\utils::is_feature_enabled()) {
        return;
    }

    // Only display if the user has the approveresponses capability.
    if (has_capability('local/forum_ai:approveresponses', $context, $USER)) {
        $pendingurl = new moodle_url('/local/forum_ai/pending.php', ['courseid' => $course->id]);
        $historyurl = new moodle_url('/local/forum_ai/history.php', ['courseid' => $course->id]);

        $navigation->add(
            get_string('pendingresponses', 'local_forum_ai'),
            $pendingurl,
            navigation_node::TYPE_SETTING,
            null,
            'forum_ai_pending',
            new pix_icon('i/warning', '')
        );

        $navigation->add(
            get_string('historyresponses', 'local_forum_ai'),
            $historyurl,
            navigation_node::TYPE_SETTING,
            null,
            'forum_ai_history',
            new pix_icon('i/log', '')
        );
    }
}

/**
 * Extends the forum module edit form with AI-related fields.
 *
 * @param mod_forum_mod_form $formwrapper
 * @param MoodleQuickForm $mform
 */
function local_forum_ai_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB, $USER;

    if (!\local_forum_ai\utils::is_feature_enabled()) {
        return;
    }

    // Only for forum.
    if ($formwrapper->get_current()->modulename !== 'forum') {
        return;
    }

    $cm = $formwrapper->get_current();
    $forumid = $cm->instance ?? null;
    $context = context_course::instance($cm->course);

    // Only users with capability to configure AI for forum can see settings.
    if (!has_capability('local/forum_ai:approveresponses', $context, $USER)) {
        return;
    }

    $rawdefaultenabled = get_config('local_forum_ai', 'default_enabled');
    $rawdefaultrequireapproval = get_config('local_forum_ai', 'default_require_approval');
    $rawdefaultreplymessage = get_config('local_forum_ai', 'default_reply_message');
    $rawdefaultinitconversation = get_config('local_forum_ai', 'default_enablediainitconversation');
    $rawdefaultusedelay = get_config('local_forum_ai', 'default_usedelay');
    $rawdefaultdelayminutes = get_config('local_forum_ai', 'default_delayminutes');

    $defaultenabled = ($rawdefaultenabled === false || $rawdefaultenabled === '') ? 1 : (int)$rawdefaultenabled;
    $defaultrequireapproval =
        ($rawdefaultrequireapproval === false || $rawdefaultrequireapproval === '') ? 1 : (int)$rawdefaultrequireapproval;
    $defaultreplymessage =
        ($rawdefaultreplymessage === false || trim((string)$rawdefaultreplymessage) === '')
            ? get_string('default_reply_message', 'local_forum_ai')
            : (string)$rawdefaultreplymessage;
    $defaultinitconversation =
        ($rawdefaultinitconversation === false || $rawdefaultinitconversation === '') ? 0 : (int)$rawdefaultinitconversation;
    $defaultusedelay = ($rawdefaultusedelay === false || $rawdefaultusedelay === '') ? 0 : (int)$rawdefaultusedelay;
    $defaultdelayminutes = ($rawdefaultdelayminutes === false || $rawdefaultdelayminutes === '')
        ? 60
        : max(1, (int)$rawdefaultdelayminutes);
    $defaultquestionturns = \local_forum_ai\utils::get_default_question_turns();
    $globalenabled = \local_forum_ai\utils::is_global_ai_enabled();

    // Default values.
    $defaults = (object)[
        'enabled' => $defaultenabled,
        'require_approval' => $defaultrequireapproval,
        'reply_message' => $defaultreplymessage,
        'enablediainitconversation' => $defaultinitconversation,
        'allowedroles' => [],
        'allowedroles_saved' => false,
        'graderid' => null,
        'usedelay' => $defaultusedelay,
        'delayminutes' => $defaultdelayminutes,
        'questionturns' => $defaultquestionturns,
    ];

    // Load custom config if exists.
    if ($forumid && $DB->record_exists('local_forum_ai_config', ['forumid' => $forumid])) {
        $record = $DB->get_record('local_forum_ai_config', ['forumid' => $forumid]);

        $defaults->enabled = $record->enabled;
        $defaults->require_approval = $record->require_approval;
        $defaults->reply_message = $record->reply_message;
        $defaults->enablediainitconversation = $record->enablediainitconversation ?? 0;
        $defaults->graderid = $record->graderid ?? null;
        $defaults->allowedroles_saved = true;
        $defaults->usedelay = $record->usedelay ?? 0;
        $defaults->delayminutes = max(1, (int)($record->delayminutes ?? 60));
        $defaults->questionturns = \local_forum_ai\utils::normalize_question_turns(
            $record->questionturns ?? $defaultquestionturns
        );

        if (empty($record->allowedroles)) {
            $defaults->allowedroles = [];
        } else {
            $defaults->allowedroles = explode(',', $record->allowedroles);
        }
    }

    if (!$globalenabled) {
        $defaults->enabled = 0;
    }

    // Header.
    $mform->addElement('header', 'local_forum_ai_header', get_string('datacurso_custom', 'local_forum_ai'));

    // Enabled AI.
    $mform->addElement(
        'select',
        'local_forum_ai_enabled',
        get_string('enabled', 'local_forum_ai'),
        [0 => get_string('no'), 1 => get_string('yes')]
    );
    $mform->setDefault('local_forum_ai_enabled', $defaults->enabled);

    // Enable AI init conversation.
    $mform->addElement('select', 'enablediainitconversation', get_string('enablediainitconversation', 'local_forum_ai'), [
        0 => get_string('no', 'local_forum_ai'),
        1 => get_string('yes', 'local_forum_ai'),
    ]);
    $mform->setType('enablediainitconversation', PARAM_INT);
    $mform->addHelpButton('enablediainitconversation', 'enablediainitconversation', 'local_forum_ai');
    $mform->setDefault('enablediainitconversation', $defaults->enablediainitconversation);

    $questionturnoptions = [
        0 => '0',
        1 => '1',
        2 => '2',
        3 => '3',
    ];
    $mform->addElement(
        'select',
        'local_forum_ai_questionturns',
        get_string('questionturns', 'local_forum_ai'),
        $questionturnoptions
    );
    $mform->setType('local_forum_ai_questionturns', PARAM_INT);
    $mform->addHelpButton('local_forum_ai_questionturns', 'questionturns', 'local_forum_ai');
    $mform->setDefault('local_forum_ai_questionturns', $defaults->questionturns);

    // Roles allowed to trigger AI.
    $roles = $DB->get_records('role', null, 'sortorder ASC');
    $roleoptions = [];
    $defaultroles = [];

    // Default: student roles.
    $studentroles = $DB->get_records('role', ['archetype' => 'student']);
    foreach ($studentroles as $sr) {
        $defaultroles[] = $sr->id;
    }

    foreach ($roles as $role) {
        $roleoptions[$role->id] = role_get_name($role);
    }

    $mform->addElement(
        'autocomplete',
        'allowedroles',
        get_string('allowedroles', 'local_forum_ai'),
        $roleoptions,
        ['multiple' => true, 'noselectionstring' => get_string('none')]
    );
    $mform->setType('allowedroles', PARAM_RAW);
    $mform->addHelpButton('allowedroles', 'allowedroles', 'local_forum_ai');

    if ($defaults->allowedroles_saved) {
        $mform->setDefault('allowedroles', $defaults->allowedroles);
    } else {
        $mform->setDefault('allowedroles', $defaultroles);
    }

    // Require approval.
    $mform->addElement(
        'select',
        'local_forum_ai_require_approval',
        get_string('require_approval', 'local_forum_ai'),
        [1 => get_string('yes'), 0 => get_string('no')]
    );
    $mform->setDefault('local_forum_ai_require_approval', $defaults->require_approval);

    $mform->addElement(
        'select',
        'local_forum_ai_usedelay',
        get_string('usedelay', 'local_forum_ai'),
        [0 => get_string('no'), 1 => get_string('yes')]
    );
    $mform->addHelpButton('local_forum_ai_usedelay', 'usedelay', 'local_forum_ai');
    $mform->setDefault('local_forum_ai_usedelay', $defaults->usedelay);

    $mform->addElement(
        'text',
        'local_forum_ai_delayminutes',
        get_string('delayminutes', 'local_forum_ai')
    );
    $mform->setType('local_forum_ai_delayminutes', PARAM_INT);
    $mform->addRule('local_forum_ai_delayminutes', null, 'numeric', null, 'client');
    $mform->addHelpButton('local_forum_ai_delayminutes', 'delayminutes', 'local_forum_ai');
    $mform->setDefault('local_forum_ai_delayminutes', $defaults->delayminutes);

    // Hide unless delay enabled.
    $mform->hideIf('local_forum_ai_usedelay', 'local_forum_ai_enabled', 'neq', 1);
    $mform->hideIf('local_forum_ai_usedelay', 'local_forum_ai_require_approval', 'eq', 1);

    $mform->hideIf('local_forum_ai_delayminutes', 'local_forum_ai_usedelay', 'neq', 1);
    $mform->hideIf('local_forum_ai_delayminutes', 'local_forum_ai_enabled', 'neq', 1);
    $mform->hideIf('local_forum_ai_delayminutes', 'local_forum_ai_require_approval', 'eq', 1);
    $mform->hideIf('local_forum_ai_questionturns', 'local_forum_ai_enabled', 'neq', 1);

    // Users enrolled who can either rate or grade.
    $eligibleusers = [];

    // Users with mod/forum:rate.
    $rators = get_enrolled_users($context, 'mod/forum:rate');
    foreach ($rators as $u) {
        $eligibleusers[$u->id] = fullname($u);
    }

    // Users with mod/forum:grade.
    $graders = get_enrolled_users($context, 'mod/forum:grade');
    foreach ($graders as $u) {
        $eligibleusers[$u->id] = fullname($u);
    }

    // Sort list.
    if (!empty($eligibleusers)) {
        \core_collator::asort($eligibleusers);
    }

    // Ensure saved grader appears even if not enrolled (past config).
    if ($defaults->graderid && !isset($eligibleusers[$defaults->graderid])) {
        $saveduser = $DB->get_record(
            'user',
            ['id' => $defaults->graderid],
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
        );
        if ($saveduser) {
            $eligibleusers[$saveduser->id] = fullname($saveduser);
        }
    }

    $mform->addElement(
        'autocomplete',
        'local_forum_ai_grader',
        get_string('autogradegrader', 'local_forum_ai'),
        $eligibleusers,
        [
            'multiple' => false,
            'tags' => false,
            'maxitems' => 1,
            'noselectionstring' => get_string('none'),
        ]
    );
    $mform->setType('local_forum_ai_grader', PARAM_INT);
    $mform->addHelpButton('local_forum_ai_grader', 'autogradegrader', 'local_forum_ai');

    if ($defaults->graderid) {
        $mform->setDefault('local_forum_ai_grader', (int)$defaults->graderid);
    }

    // Hide unless AI is enabled.
    $mform->hideIf('local_forum_ai_grader', 'local_forum_ai_enabled', 'neq', 1);
    $mform->hideIf('local_forum_ai_grader', 'local_forum_ai_require_approval', 'eq', 1);

    // Reply AI template.
    $mform->addElement(
        'textarea',
        'local_forum_ai_reply_message',
        get_string('reply_message', 'local_forum_ai'),
        'wrap="virtual" rows="3" cols="50"'
    );
    $mform->setType('local_forum_ai_reply_message', PARAM_TEXT);
    $mform->setDefault('local_forum_ai_reply_message', $defaults->reply_message);
}

/**
 * Saves or updates forum AI configuration when editing a forum.
 *
 * @param stdClass $data
 * @param stdClass $course
 * @return stdClass
 */
function local_forum_ai_coursemodule_edit_post_actions($data, $course) {
    global $DB;

    if ($data->modulename !== 'forum') {
        return $data;
    }

    if (!\local_forum_ai\utils::is_feature_enabled()) {
        return $data;
    }

    $record = $DB->get_record('local_forum_ai_config', ['forumid' => $data->instance]);

    $config = new stdClass();
    $config->forumid = $data->instance;
    $config->enabled = $data->local_forum_ai_enabled ?? 0;
    $config->require_approval = $data->local_forum_ai_require_approval ?? 1;
    $config->reply_message = $data->local_forum_ai_reply_message ?? '';
    $config->enablediainitconversation = $data->enablediainitconversation ?? 0;
    $config->graderid = $data->local_forum_ai_grader ?? null;
    $config->usedelay = $data->local_forum_ai_usedelay ?? 0;
    $config->delayminutes = max(1, (int)($data->local_forum_ai_delayminutes ?? 60));
    $config->questionturns = \local_forum_ai\utils::normalize_question_turns(
        $data->local_forum_ai_questionturns ?? \local_forum_ai\utils::get_default_question_turns()
    );

    if (!\local_forum_ai\utils::is_global_ai_enabled()) {
        $config->enabled = 0;
    }

    // Save null if not selected roles.
    if (!empty($data->allowedroles) && is_array($data->allowedroles)) {
        $config->allowedroles = implode(',', $data->allowedroles);
    } else {
        $config->allowedroles = null;
    }

    $config->timemodified = time();

    if ($record) {
        $config->id = $record->id;
        $DB->update_record('local_forum_ai_config', $config);
    } else {
        $config->timecreated = time();
        $DB->insert_record('local_forum_ai_config', $config);
    }

    return $data;
}
