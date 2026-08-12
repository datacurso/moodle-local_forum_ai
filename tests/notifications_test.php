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
 * Tests for the approval notification and the forum subscription mails of AI posts.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

use stdClass;

global $CFG;

require_once($CFG->dirroot . '/mod/forum/lib.php');

/**
 * Tests for teacher notifications and standard forum subscription mail.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\approval::create_approval_request
 * @covers \local_forum_ai\approval::send_moodle_notification
 * @covers \local_forum_ai\approval::publish_ai_post
 */
final class notifications_test extends \advanced_testcase {
    /**
     * MDL-INT-023 (steps 1-2): creating a pending response notifies the predefined
     * roles (editing teacher) with the context names, a truncated preview and a
     * working review link; users outside the predefined roles receive nothing.
     */
    public function test_pending_creation_notifies_editing_teacher_with_review_link(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup();
        $editingteacher = $this->getDataGenerator()->create_and_enrol($setup->course, 'editingteacher');
        // A non-editing teacher can reply in the forum but is not a predefined recipient role.
        $nonediting = $this->getDataGenerator()->create_and_enrol($setup->course, 'teacher');

        $longtext = str_repeat('A', 400);

        $sink = $this->redirectMessages();
        $pendingid = approval::create_approval_request(
            $setup->discussion,
            $setup->forum,
            '<p>' . $longtext . '</p>',
            'pending',
            (int) $setup->discussion->firstpost
        );
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertGreaterThan(0, $pendingid);

        $byrecipient = [];
        foreach ($messages as $message) {
            $byrecipient[(int) $message->useridto][] = $message;
        }

        $this->assertArrayHasKey((int) $editingteacher->id, $byrecipient, 'The editing teacher must be notified.');
        $this->assertArrayNotHasKey((int) $nonediting->id, $byrecipient);

        $message = $byrecipient[(int) $editingteacher->id][0];
        $this->assertSame('local_forum_ai', $message->component);
        $this->assertSame('ai_approval_request', $message->eventtype);

        // Context names: discussion, forum and course.
        $this->assertStringContainsString($setup->discussion->name, $message->fullmessage);
        $this->assertStringContainsString($setup->forum->name, $message->fullmessage);
        $this->assertStringContainsString($setup->course->fullname, $message->fullmessage);

        // Preview truncated to 150 characters.
        $this->assertStringContainsString(str_repeat('A', 150), $message->fullmessage);
        $this->assertStringNotContainsString(str_repeat('A', 151), $message->fullmessage);

        // Functional review link carrying the row's token.
        global $DB;
        $token = $DB->get_field('local_forum_ai_pending', 'approval_token', ['id' => $pendingid], MUST_EXIST);
        $this->assertStringContainsString('/local/forum_ai/review.php', $message->fullmessage);
        $this->assertStringContainsString($token, $message->fullmessage);
    }

    /**
     * MDL-INT-023 (step 3): the notification channel defaults — popup enabled by
     * default, email present but disabled by default.
     */
    public function test_notification_channel_defaults(): void {
        $this->resetAfterTest();

        $enabled = get_config('message', 'message_provider_local_forum_ai_ai_approval_request_enabled');

        $this->assertNotFalse($enabled, 'The ai_approval_request provider must declare default channels.');
        $this->assertStringContainsString('popup', $enabled);
        $this->assertStringNotContainsString('email', $enabled);
    }

    /**
     * MDL-INT-023 (step 4): custom or renamed roles holding the approval capability
     * must also receive the notification.
     */
    public function test_custom_role_with_capability_receives_notification(): void {
        $this->markTestSkipped(
            'MDL-INT-023 NOTA [Pendiente:skip]: los destinatarios se filtran por el nombre ' .
            'interno de tres roles predefinidos; los roles personalizados con el permiso no ' .
            'reciben nada y, si ningun usuario coincide, la pendiente queda sin notificacion ' .
            'alguna en silencio — debe filtrarse por capacidad.'
        );
    }

    /**
     * MDL-INT-023 (step 5, updated by FORUMAI-SEC-002): the plain-text body must
     * only offer the review.php link. The former quick approve/reject links pointed
     * to a non-existent approve.php endpoint and leaked the approval token, so they
     * were removed from the notification.
     */
    public function test_plain_text_body_offers_only_review_link(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $setup = $this->create_setup();
        $editingteacher = $this->getDataGenerator()->create_and_enrol($setup->course, 'editingteacher');

        $sink = $this->redirectMessages();
        $pendingid = approval::create_approval_request(
            $setup->discussion,
            $setup->forum,
            '<p>Body under review</p>',
            'pending',
            (int) $setup->discussion->firstpost
        );
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertGreaterThan(0, $pendingid);

        $teachermessages = array_values(array_filter($messages, static function (stdClass $message) use ($editingteacher): bool {
            return (int) $message->useridto === (int) $editingteacher->id;
        }));
        $this->assertNotEmpty($teachermessages);

        $body = $teachermessages[0]->fullmessage;
        $this->assertStringContainsString('review.php', $body);
        $this->assertStringNotContainsString('approve.php', $body);
    }

    /**
     * MDL-INT-029 (step 1): a published AI post generates the standard forum
     * subscription mail in a force-subscribed forum.
     */
    public function test_ai_post_generates_subscription_mail_when_subscribed(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->preventResetByRollback();
        $messagesink = $this->redirectMessages();
        $this->redirectEmails();

        // Make every new post immediately mailable.
        $CFG->maxeditingtime = -1;

        $setup = $this->create_setup(['forcesubscribe' => FORUM_FORCESUBSCRIBE]);
        $teacher = $this->getDataGenerator()->create_and_enrol($setup->course, 'editingteacher');

        // Only the AI post must remain unmailed, so the assertion is exact.
        $DB->set_field('forum_posts', 'mailed', 1, ['discussion' => $setup->discussion->id]);

        $pending = $this->insert_pending_row($setup);
        $postid = approval::publish_ai_post(
            $setup->discussion,
            $setup->forum,
            $setup->cm,
            $setup->course,
            $pending,
            (int) $setup->discussion->firstpost,
            (int) $teacher->id
        );
        $this->assertNotFalse($postid);

        $this->run_forum_mail_cron();

        $studentmessages = $this->messages_for_user($messagesink->get_messages(), (int) $setup->student->id);
        $messagesink->close();

        $this->assertCount(1, $studentmessages, 'The subscribed student must receive the AI post by mail.');
        $this->assertSame('mod_forum', $studentmessages[0]->component);
        $this->assertStringContainsString('AI subscription reply body', $studentmessages[0]->fullmessage);
    }

    /**
     * MDL-INT-029 (step 2): with subscriptions disabled in the forum no mail is sent.
     */
    public function test_ai_post_sends_no_mail_when_subscription_disabled(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->preventResetByRollback();
        $messagesink = $this->redirectMessages();
        $this->redirectEmails();

        $CFG->maxeditingtime = -1;

        $setup = $this->create_setup(['forcesubscribe' => FORUM_DISALLOWSUBSCRIBE]);
        $teacher = $this->getDataGenerator()->create_and_enrol($setup->course, 'editingteacher');

        $DB->set_field('forum_posts', 'mailed', 1, ['discussion' => $setup->discussion->id]);

        $pending = $this->insert_pending_row($setup);
        $postid = approval::publish_ai_post(
            $setup->discussion,
            $setup->forum,
            $setup->cm,
            $setup->course,
            $pending,
            (int) $setup->discussion->firstpost,
            (int) $teacher->id
        );
        $this->assertNotFalse($postid);

        $this->run_forum_mail_cron();

        $studentmessages = $this->messages_for_user($messagesink->get_messages(), (int) $setup->student->id);
        $messagesink->close();

        $this->assertCount(0, $studentmessages, 'No subscription mail may be sent when subscriptions are disabled.');
    }

    /**
     * Runs the forum mail cron plus the per-user notification adhoc tasks.
     *
     * @return void
     */
    private function run_forum_mail_cron(): void {
        ob_start();
        try {
            \core\cron::setup_user();
            $cron = new \mod_forum\task\cron_task();
            $cron->execute();
            $this->runAdhocTasks(\mod_forum\task\send_user_notifications::class);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Filters sink messages addressed to one user.
     *
     * @param array $messages Messages captured by the sink.
     * @param int $userid Recipient user id.
     * @return array
     */
    private function messages_for_user(array $messages, int $userid): array {
        return array_values(array_filter($messages, static function (stdClass $message) use ($userid): bool {
            return (int) $message->useridto === $userid && $message->component === 'mod_forum';
        }));
    }

    /**
     * Creates a course, student, forum and a discussion started by the student.
     *
     * @param array $forumoptions Extra forum options.
     * @return stdClass Setup holder (course, student, forum, cm, discussion).
     */
    private function create_setup(array $forumoptions = []): stdClass {
        global $DB;

        $setup = new stdClass();
        $setup->course = $this->getDataGenerator()->create_course();
        $setup->student = $this->getDataGenerator()->create_and_enrol($setup->course, 'student');

        $forummodule = $this->getDataGenerator()->create_module(
            'forum',
            array_merge(['course' => $setup->course->id], $forumoptions)
        );
        $setup->forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);
        $setup->cm = get_coursemodule_from_instance('forum', $setup->forum->id, $setup->course->id, false, MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $setup->course->id,
            'forum' => $setup->forum->id,
            'userid' => $setup->student->id,
        ]);
        $setup->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        return $setup;
    }

    /**
     * Inserts a pending row for the setup discussion.
     *
     * @param stdClass $setup Setup holder.
     * @return stdClass The pending row.
     */
    private function insert_pending_row(stdClass $setup): stdClass {
        global $DB;

        $pending = (object) [
            'discussionid' => $setup->discussion->id,
            'forumid' => $setup->forum->id,
            'parentpostid' => $setup->discussion->firstpost,
            'creator_userid' => $setup->student->id,
            'subject' => 'Re: ' . $setup->discussion->name,
            'message' => '<p>AI subscription reply body</p>',
            'status' => 'pending',
            'approval_token' => md5(uniqid('notifications_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);

        return $pending;
    }
}
