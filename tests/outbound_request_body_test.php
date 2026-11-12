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

namespace local_forum_ai;

use aiprovider_datacurso\httpclient\ai_services_api;
use aiprovider_datacurso\local\license_region;
use aiprovider_datacurso\local\outbound_privacy;
use local_forum_ai\local\outbound_payload;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/recording_curl.php');

/**
 * FAI-PRIV-001-R1: the body that really goes on the wire to the AI service.
 *
 * The real plugin code builds each request and the real Datacurso provider client merges its
 * default fields, pseudonymises and encodes it; only the cURL transport is replaced, to record
 * the exact JSON instead of sending it. Each test checks that the discussions and their content
 * travel and that no real identifier of a person or an activity does.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group     local_forum_ai
 * @covers    \local_forum_ai\ai_service
 * @covers    \local_forum_ai\local\outbound_payload
 * @covers    \local_forum_ai\task\process_ai_post
 * @covers    \local_forum_ai\external\process_review
 */
final class outbound_request_body_test extends \advanced_testcase {
    /** @var string[] Real names of the people in the thread. */
    private const NAMES = ['Ana', 'Torres', 'Bruno', 'Díaz', 'Carla', 'Mendoza'];

    /** @var string[] Keys the provider client adds to every request (tenant_id on Moodle Workplace). */
    private const PROVIDER_FIELDS = ['site_id', 'userid', 'timezone', 'lang', 'site_url', 'tenant_id'];

    /**
     * @var string[] Keys that may name an identifier anywhere in the body. tenant_id is the Workplace
     *      tenant the provider bills the request to: an organisation, not a person or an activity.
     */
    private const ID_KEYS = ['id', 'userid', 'site_id', 'forum_id', 'tenant_id'];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(outbound_privacy::class)) {
            $this->markTestSkipped('aiprovider_datacurso with outbound_privacy is not installed.');
        }
    }

    #[\Override]
    protected function tearDown(): void {
        ai_service::set_client_for_testing(null);
        parent::tearDown();
    }

    /**
     * The reply request carries the discussion content and no real identifier.
     */
    public function test_chat_request_body_carries_content_and_no_real_identifier(): void {
        $people = $this->create_named_forum();
        $curl = $this->install_provider_client(['reply' => 'Thanks [STUDENT_NAME]', 'grade' => null]);

        $task = new task\process_ai_post();
        $task->set_custom_data((object) ['postid' => $people->post->id, 'cmid' => $people->cm->id]);
        $sink = $this->redirectMessages();
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
            $sink->close();
        }

        $this->assertCount(1, $curl->calls);
        $this->assertStringEndsWith('/forum/chat/v2', $curl->calls[0]['url']);
        $raw = $curl->last_raw_body();
        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        // The content the AI grades travels.
        $this->assertStringContainsString('the membrane protects the cell', $body['post']['message']);
        $this->assertStringContainsString('What is a cell membrane?', $raw);
        $this->assertStringContainsString('Mitochondria produce energy', $raw);
        $this->assertNotEmpty($body['thread_history']);
        $this->assertNotEmpty($body['own_main_posts']);

        // Only documented fields leave the site.
        $this->assertSame([], array_diff(
            array_keys($body),
            array_merge(outbound_payload::CHAT_FIELDS, self::PROVIDER_FIELDS)
        ));

        // No real identifier of a person or an activity.
        $this->assert_no_real_identifier($raw, $people);
        $this->assertSame(outbound_privacy::pseudonymise_userid($people->student->id), $body['userid']);
        foreach (['thread_history', 'own_main_posts'] as $key) {
            $this->assertSame(range(1, count($body[$key])), array_column($body[$key], 'id'));
        }
    }

    /**
     * The participation grading request carries the discussions and no real identifier.
     */
    public function test_grade_request_body_carries_content_and_no_real_identifier(): void {
        $people = $this->create_named_forum();
        $curl = $this->install_provider_client(['feedback' => 'Good work, [STUDENT_NAME].']);

        $this->setUser($people->teacher);
        external\process_review::execute((int) $people->cm->id, (int) $people->student->id);

        $this->assertCount(1, $curl->calls);
        $this->assertStringEndsWith('/forum/grade', $curl->calls[0]['url']);
        $raw = $curl->last_raw_body();
        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        // The content the AI grades travels.
        $participation = $body['forum_participations'][0]['participation'];
        $answers = implode(' | ', array_column($participation['discussions'], 'answer'));
        $this->assertStringContainsString('the membrane protects the cell', $answers);
        $this->assertStringContainsString('Mitochondria produce energy', $answers);
        $this->assertSame($people->forum->name, $participation['forum']);

        // Only documented fields leave the site.
        $this->assertSame([], array_diff(
            array_keys($body),
            array_merge(outbound_payload::GRADE_FIELDS, self::PROVIDER_FIELDS)
        ));

        // No real identifier of a person or an activity.
        $this->assert_no_real_identifier($raw, $people);
        $this->assertSame(
            outbound_privacy::pseudonymise_userid($people->student->id),
            $body['forum_participations'][0]['userid']
        );
        $this->assertSame(outbound_privacy::pseudonymise_userid($people->teacher->id), $body['userid']);
        $this->assertSame(outbound_payload::FORUM_REFERENCE, $participation['forum_id']);
    }

    /**
     * Assert that the body holds no name, e-mail address or Moodle id of a person or an activity.
     *
     * Every key that can carry an identifier is checked: thread ids are positions, user ids are
     * 32-character pseudonyms that differ from every real user id, and no other id-like key exists.
     *
     * @param string $raw Raw JSON body.
     * @param stdClass $people Fixture holder.
     * @return void
     */
    private function assert_no_real_identifier(string $raw, stdClass $people): void {
        foreach (self::NAMES as $name) {
            $this->assertDoesNotMatchRegularExpression(
                '/(?<![\pL\pN])' . preg_quote($name, '/') . '(?![\pL\pN])/iu',
                $raw,
                "'{$name}' must not reach the AI service."
            );
        }
        $this->assertStringNotContainsString('@example.com', $raw);

        $realuserids = array_map('strval', [$people->student->id, $people->peer->id, $people->teacher->id]);
        $leaves = [];
        $this->collect_leaves(json_decode($raw, true), '', $leaves);
        foreach ($leaves as $path => $value) {
            $key = (string) preg_replace('/^.*\./', '', $path);
            if ($key === 'id' || !preg_match('/(^|_)id$|id$/i', $key)) {
                continue;
            }
            $this->assertContains($key, self::ID_KEYS, "Unexpected identifier field '{$path}'.");
            if ($key === 'userid') {
                $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $value, $path);
                $this->assertNotContains((string) $value, $realuserids, $path);
            }
        }
        foreach (['discussion_id', 'postid', 'cmid', 'courseid', 'forumid'] as $forbidden) {
            $this->assertStringNotContainsString('"' . $forbidden . '"', $raw);
        }
    }

    /**
     * Flatten a decoded body into dotted paths and scalar values.
     *
     * @param mixed $data Decoded data.
     * @param string $prefix Current path.
     * @param array $leaves Collected leaves.
     * @return void
     */
    private function collect_leaves($data, string $prefix, array &$leaves): void {
        if (!is_array($data)) {
            $leaves[$prefix] = $data;
            return;
        }
        foreach ($data as $key => $value) {
            $this->collect_leaves($value, $prefix === '' ? (string) $key : $prefix . '.' . $key, $leaves);
        }
    }

    /**
     * Install the real provider client with a recording transport as the plugin's AI client.
     *
     * @param array $answer Decoded answer of the AI service.
     * @return recording_curl
     */
    private function install_provider_client(array $answer): recording_curl {
        global $DB;

        $this->resetAfterTest();
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        $licensekey = 'forum-ai-test-key';
        set_config('licensekey', $licensekey, 'aiprovider_datacurso');
        // Resolve the region beforehand, so building a client does not ask the shop. The provider
        // keeps it per licence (sha1 of the key) for the site and, on Workplace, for every tenant.
        $region = [
            license_region::REGION => '0',
            license_region::FINGERPRINT => sha1($licensekey),
            license_region::CHECKED => (string)time(),
        ];
        $tenantids = [];
        if (class_exists(\aiprovider_datacurso\local\tenant_config::class) && $DB->get_manager()->table_exists('tool_tenant')) {
            $tenantids = $DB->get_fieldset_select('tool_tenant', 'id', '1 = 1');
        }
        foreach ($region as $name => $value) {
            set_config($name, $value, 'aiprovider_datacurso');
            foreach ($tenantids as $tenantid) {
                \aiprovider_datacurso\local\tenant_config::set('aiprovider_datacurso', (int)$tenantid, $name, $value);
            }
        }

        $curl = new recording_curl($answer);
        $client = new class ($curl) extends ai_services_api {
            /**
             * Constructor.
             *
             * @param recording_curl $recorder Transport double.
             */
            public function __construct(
                /** @var recording_curl Transport double. */
                private recording_curl $recorder
            ) {
                parent::__construct(null, 'https://ai.example.invalid', 'https://eu.ai.example.invalid');
            }

            #[\Override]
            protected function create_curl(): \curl {
                return $this->recorder;
            }
        };
        ai_service::set_client_for_testing($client);

        return $curl;
    }

    /**
     * A graded forum where a peer opens a discussion, the teacher answers and the student
     * replies naming both, plus another discussion opened by the student.
     *
     * @return stdClass Holder (course, forum, cm, student, peer, teacher, post).
     */
    private function create_named_forum(): stdClass {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $people = new stdClass();
        $people->course = $generator->create_course();
        $people->student = $generator->create_and_enrol($people->course, 'student', [
            'firstname' => 'Ana', 'lastname' => 'Torres', 'email' => 'ana.torres@example.com',
        ]);
        $people->peer = $generator->create_and_enrol($people->course, 'student', [
            'firstname' => 'Bruno', 'lastname' => 'Díaz', 'email' => 'bruno.diaz@example.com',
        ]);
        $people->teacher = $generator->create_and_enrol($people->course, 'editingteacher', [
            'firstname' => 'Carla', 'lastname' => 'Mendoza', 'email' => 'carla.mendoza@example.com',
        ]);

        $forummodule = $generator->create_module('forum', ['course' => $people->course->id, 'grade_forum' => 10]);
        $people->cm = get_coursemodule_from_instance('forum', $forummodule->id, $people->course->id, false, MUST_EXIST);
        $people->forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $forumgenerator->create_discussion([
            'course' => $people->course->id,
            'forum' => $people->forum->id,
            'userid' => $people->student->id,
            'name' => 'Ana on organelles',
            'message' => 'Mitochondria produce energy for the cell.',
        ]);
        $discussion = $forumgenerator->create_discussion([
            'course' => $people->course->id,
            'forum' => $people->forum->id,
            'userid' => $people->peer->id,
            'name' => 'Question from Bruno Díaz',
            'message' => 'Hi all, I am Bruno. What is a cell membrane?',
        ]);
        $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $people->teacher->id,
            'message' => 'Good question Bruno. Ana, can you help? Write to carla.mendoza@example.com.',
        ]);
        $people->post = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $people->student->id,
            'subject' => 'Re: Question from Bruno Díaz',
            'message' => 'Thanks Carla! Bruno, the membrane protects the cell. Mail me at ana.torres@example.com.',
        ]);

        $DB->insert_record('local_forum_ai_config', (object) [
            'forumid' => $people->forum->id,
            'enabled' => 1,
            'require_approval' => 1,
            'allowedroles' => (string) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST),
            'reply_message' => 'Test prompt',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return $people;
    }
}
