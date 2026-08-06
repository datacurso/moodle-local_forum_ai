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
 * Privacy metadata tests for local_forum_ai.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\metadata\types\external_location;

/**
 * Tests that the privacy metadata declares every table and the external AI transfer.
 *
 * @coversDefaultClass \local_forum_ai\privacy\provider
 * Covers: MDL-INT-027 — Privacidad: exportacion y eliminacion de datos personales
 *
 * @group local_forum_ai
 * @group local_forum_ai_privacy
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The metadata must declare the external AI provider location with the sent data.
     *
     * @covers ::get_metadata
     */
    public function test_get_metadata_declares_external_location(): void {
        $collection = new collection('local_forum_ai');
        provider::get_metadata($collection);

        $external = null;
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof external_location && $item->get_name() === 'datacurso_ai') {
                $external = $item;
                break;
            }
        }

        $this->assertNotNull($external, 'An external_location for the AI provider must be declared.');
        $fields = $external->get_privacy_fields();
        $this->assertArrayHasKey('userid', $fields);
        $this->assertArrayHasKey('post_content', $fields);
        $this->assertArrayHasKey('thread_history', $fields);
    }

    /**
     * All personal-data tables must be declared, including the processing queue.
     *
     * @covers ::get_metadata
     */
    public function test_get_metadata_declares_all_tables(): void {
        $collection = new collection('local_forum_ai');
        provider::get_metadata($collection);

        $tables = [];
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof database_table) {
                $tables[] = $item->get_name();
            }
        }

        $this->assertContains('local_forum_ai_pending', $tables);
        $this->assertContains('local_forum_ai_config', $tables);
        $this->assertContains('local_forum_ai_queue', $tables);
    }

    /**
     * The three table declarations must cover their complete field lists.
     *
     * @covers ::get_metadata
     */
    public function test_get_metadata_declares_all_fields(): void {
        $collection = new collection('local_forum_ai');
        provider::get_metadata($collection);

        $fieldsbytable = [];
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof database_table) {
                $fieldsbytable[$item->get_name()] = array_keys($item->get_privacy_fields());
            }
        }

        $this->assertEqualsCanonicalizing(
            [
                'forumid', 'enabled', 'reply_message', 'require_approval', 'questionturns', 'replyinlocked',
                'graderid', 'enablediainitconversation', 'allowedroles', 'usedelay', 'delayminutes',
                'timecreated', 'timemodified',
            ],
            $fieldsbytable['local_forum_ai_config']
        );
        $this->assertEqualsCanonicalizing(
            [
                'creator_userid', 'discussionid', 'forumid', 'parentpostid', 'postid', 'message', 'subject',
                'grade', 'status', 'approved_at', 'approval_token', 'timecreated', 'timemodified',
            ],
            $fieldsbytable['local_forum_ai_pending']
        );
        $this->assertEqualsCanonicalizing(
            ['type', 'payload', 'timecreated', 'timetoprocess', 'processed'],
            $fieldsbytable['local_forum_ai_queue']
        );
    }

    /**
     * Every string key referenced by the metadata collection must exist.
     *
     * This guards English completeness only: string_exists() falls back to the
     * English pack, so gaps in the translated packs are not detected here.
     *
     * @covers ::get_metadata
     */
    public function test_metadata_string_keys_exist(): void {
        $collection = new collection('local_forum_ai');
        provider::get_metadata($collection);

        foreach ($collection->get_collection() as $item) {
            $this->assertTrue(
                get_string_manager()->string_exists($item->get_summary(), 'local_forum_ai'),
                'Missing summary string: ' . $item->get_summary()
            );
            foreach ($item->get_privacy_fields() as $key) {
                $this->assertTrue(
                    get_string_manager()->string_exists($key, 'local_forum_ai'),
                    'Missing field string: ' . $key
                );
            }
        }
    }
}
