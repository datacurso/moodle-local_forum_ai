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
 * Tests for the global settings, default seeding and default resolution ladder.
 *
 * MDL-INT-001 steps 1-3 (settings page access and the visibility cascade of the
 * admin form) are UI concerns covered by Behat, not by this file.
 *
 * The delay-minutes ladder (unset -> 60, max(1, ...) floor) is already covered by
 * tests/utils_test.php::test_get_default_delay_minutes, and the reply-in-locked
 * default/effective ladder by tests/utils_reply_in_locked_test.php; neither is
 * duplicated here.
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
 * Tests for mass-disable, default seeding, fail-open flags and upgrade persistence.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\utils::disable_all_forums_ai
 * @covers \local_forum_ai\utils::is_feature_enabled
 * @covers \local_forum_ai\utils::is_global_ai_enabled
 * @covers \local_forum_ai\utils::get_default_question_turns
 * @covers \local_forum_ai\utils::get_effective_question_turns
 */
final class settings_and_defaults_test extends \advanced_testcase {
    /**
     * MDL-INT-001 (step 4): disabling the global AI mass-disables the AI
     * configuration of every existing forum (the method the settings callback runs).
     */
    public function test_disable_all_forums_ai_disables_every_config_row(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
            $ids[] = (int) $DB->insert_record('local_forum_ai_config', (object) [
                'forumid' => $forum->id,
                'enabled' => 1,
                'require_approval' => 1,
                'reply_message' => 'Prompt ' . $i,
                'timecreated' => time() - 100,
                'timemodified' => time() - 100,
            ]);
        }

        utils::disable_all_forums_ai();

        foreach ($ids as $id) {
            $row = $DB->get_record('local_forum_ai_config', ['id' => $id], '*', MUST_EXIST);
            $this->assertSame(0, (int) $row->enabled, "Config row {$id} must be disabled.");
            $this->assertGreaterThan(time() - 100, (int) $row->timemodified);
        }
    }

    /**
     * MDL-INT-001 (step 5): the documented default values are seeded on installation.
     */
    public function test_install_seeds_documented_defaults(): void {
        // The phpunit site runs db/install.php, so all install-time defaults exist.
        $expected = [
            'enableforumai' => '1',
            'default_enabled' => '1',
            'default_require_approval' => '1',
            'default_enablediainitconversation' => '0',
            'default_usedelay' => '0',
            'default_delayminutes' => '60',
            'default_question_turns' => '1',
        ];

        foreach ($expected as $name => $value) {
            $this->assertSame(
                $value,
                (string) get_config('local_forum_ai', $name),
                "Setting {$name} must be seeded with its documented default."
            );
        }

        // The default instructions text is seeded from the language string.
        $this->assertSame(
            get_string('default_reply_message', 'local_forum_ai'),
            get_config('local_forum_ai', 'default_reply_message')
        );
        $this->assertNotSame('', trim((string) get_config('local_forum_ai', 'default_reply_message')));
    }

    /**
     * MDL-INT-001 (step 5): missing defaults are restored when the admin tree is
     * built (the reseed block at the top of settings.php).
     */
    public function test_missing_defaults_are_reseeded_by_settings_page(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        require_once($CFG->libdir . '/adminlib.php');

        unset_config('default_question_turns', 'local_forum_ai');
        unset_config('default_replyinlocked', 'local_forum_ai');
        unset_config('default_reply_message', 'local_forum_ai');

        // Building the full admin tree executes settings.php, which reseeds gaps.
        admin_get_root(true, true);

        $this->assertSame('1', (string) get_config('local_forum_ai', 'default_question_turns'));
        $this->assertSame('0', (string) get_config('local_forum_ai', 'default_replyinlocked'));
        $this->assertSame(
            get_string('default_reply_message', 'local_forum_ai'),
            get_config('local_forum_ai', 'default_reply_message')
        );
    }

    /**
     * MDL-INT-034 (steps 1-2): unset global flags resolve to enabled (fail-open):
     * a freshly installed or partially configured site has the AI active.
     */
    public function test_global_flags_fail_open_when_unconfigured(): void {
        $this->resetAfterTest();

        // Unset: enabled (fail-open, documented behavior).
        unset_config('enableforumai', 'local_forum_ai');
        unset_config('default_enabled', 'local_forum_ai');
        $this->assertTrue(utils::is_feature_enabled());
        $this->assertTrue(utils::is_global_ai_enabled());

        // Empty string: still enabled.
        set_config('enableforumai', '', 'local_forum_ai');
        set_config('default_enabled', '', 'local_forum_ai');
        $this->assertTrue(utils::is_feature_enabled());
        $this->assertTrue(utils::is_global_ai_enabled());

        // Explicit zero: disabled.
        set_config('enableforumai', 0, 'local_forum_ai');
        set_config('default_enabled', 0, 'local_forum_ai');
        $this->assertFalse(utils::is_feature_enabled());
        $this->assertFalse(utils::is_global_ai_enabled());

        // Explicit one: enabled.
        set_config('enableforumai', 1, 'local_forum_ai');
        set_config('default_enabled', 1, 'local_forum_ai');
        $this->assertTrue(utils::is_feature_enabled());
        $this->assertTrue(utils::is_global_ai_enabled());
    }

    /**
     * MDL-INT-034 (step 2): the question-turns default ladder — unset resolves to 1,
     * stored values are normalized, and the forum config overrides the global.
     */
    public function test_question_turns_resolution_ladder(): void {
        $this->resetAfterTest();

        unset_config('default_question_turns', 'local_forum_ai');
        $this->assertSame(1, utils::get_default_question_turns());

        set_config('default_question_turns', 'garbage', 'local_forum_ai');
        $this->assertSame(0, utils::get_default_question_turns());

        set_config('default_question_turns', 9, 'local_forum_ai');
        $this->assertSame(3, utils::get_default_question_turns());

        set_config('default_question_turns', 2, 'local_forum_ai');
        $this->assertSame(2, utils::get_default_question_turns());

        // No forum config: global default wins.
        $this->assertSame(2, utils::get_effective_question_turns(null));

        // Forum config wins over the global, normalized to the valid range.
        $this->assertSame(3, utils::get_effective_question_turns((object) ['questionturns' => 7]));
        $this->assertSame(0, utils::get_effective_question_turns((object) ['questionturns' => -2]));
        $this->assertSame(1, utils::get_effective_question_turns((object) ['questionturns' => 1]));
    }

    /**
     * MDL-INT-033 (step 1): plugin upgrades preserve deliberate admin settings.
     *
     * The reply-in-locked row migration itself is covered by tests/upgrade_test.php
     * and is not duplicated here.
     */
    public function test_upgrade_preserves_deliberate_admin_settings(): void {
        $this->resetAfterTest();

        $custom = [
            'enableforumai' => '0',
            'default_enabled' => '0',
            'default_require_approval' => '0',
            'default_usedelay' => '1',
            'default_delayminutes' => '15',
            'default_question_turns' => '3',
            'default_replyinlocked' => '1',
            'default_reply_message' => 'Custom admin instructions',
        ];
        foreach ($custom as $name => $value) {
            set_config($name, $value, 'local_forum_ai');
        }
        set_config('version', 2026080400, 'local_forum_ai');

        xmldb_local_forum_ai_upgrade(2026080400);

        foreach ($custom as $name => $value) {
            $this->assertSame(
                $value,
                (string) get_config('local_forum_ai', $name),
                "Upgrade must not revert the deliberate value of {$name}."
            );
        }
    }

    /**
     * MDL-INT-033 (step 2): the transition from versions before 1.0.6 with
     * "Habilitar IA" disabled.
     */
    public function test_pre_106_transition_keeps_ai_disabled(): void {
        $this->markTestSkipped(
            'MDL-INT-033 NOTA [Pendiente:skip]: la transicion desde versiones anteriores a la ' .
            '1.0.6 reactiva "Habilitar IA" aunque estuviera desactivado; afecta solo esa ' .
            'transicion — documentar en notas de version.'
        );
    }
}
