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
 * Tests for forum AI upgrade steps.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/forum_ai/db/upgrade.php');

/**
 * Tests for migrating reply-in-locked forum AI settings.
 *
 * @group local_forum_ai
 * @coversNothing
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * Legacy rows that matched the site default should be converted to inherit it instead of overriding it.
     */
    public function test_replyinlocked_rows_matching_global_default_are_migrated_to_inherit(): void {
        global $DB;

        $this->resetAfterTest();

        [$forumdefault, $forumoverride] = $this->create_forums();
        set_config('default_replyinlocked', 0, 'local_forum_ai');
        set_config('version', 2026080400, 'local_forum_ai');

        $this->insert_config_row($forumdefault->id, 0);
        $this->insert_config_row($forumoverride->id, 1);

        xmldb_local_forum_ai_upgrade(2026080400);

        $defaultrow = $DB->get_record('local_forum_ai_config', ['forumid' => $forumdefault->id], '*', MUST_EXIST);
        $overriderow = $DB->get_record('local_forum_ai_config', ['forumid' => $forumoverride->id], '*', MUST_EXIST);

        $this->assertSame(\local_forum_ai\utils::REPLY_IN_LOCKED_INHERIT, (int) $defaultrow->replyinlocked);
        $this->assertSame(1, (int) $overriderow->replyinlocked);
    }

    /**
     * Creates two forums for migration tests.
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function create_forums(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $forumdefault = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $forumoverride = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        return [
            $DB->get_record('forum', ['id' => $forumdefault->id], '*', MUST_EXIST),
            $DB->get_record('forum', ['id' => $forumoverride->id], '*', MUST_EXIST),
        ];
    }

    /**
     * Inserts a minimal forum AI config row.
     *
     * @param int $forumid Forum id.
     * @param int $replyinlocked Stored reply-in-locked value.
     */
    private function insert_config_row(int $forumid, int $replyinlocked): void {
        global $DB;

        $now = time();
        $DB->insert_record('local_forum_ai_config', (object) [
            'forumid' => $forumid,
            'enabled' => 1,
            'require_approval' => 1,
            'reply_message' => 'Prompt',
            'enablediainitconversation' => 0,
            'allowedroles' => null,
            'graderid' => null,
            'usedelay' => 0,
            'delayminutes' => 60,
            'replyinlocked' => $replyinlocked,
            'questionturns' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
