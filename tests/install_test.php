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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/forum_ai/db/install.php');

/**
 * Tests for the plugin install script.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group     local_forum_ai
 * @covers    ::xmldb_local_forum_ai_install
 */
final class install_test extends \advanced_testcase {
    /**
     * A fresh Workplace install grants the tenant settings capability to the Tenant administrator role.
     */
    public function test_install_grants_tenant_settings_capability_to_tenant_admin_role(): void {
        global $CFG, $DB;

        if (!class_exists('\tool_tenant\manager')) {
            $this->markTestSkipped('Moodle Workplace (tool_tenant) is not installed.');
        }
        if (empty($CFG->tool_tenant_adminrole)) {
            $this->markTestSkipped('The Tenant administrator role does not exist on this site.');
        }
        $this->resetAfterTest();

        $roleid = (int) $CFG->tool_tenant_adminrole;
        $systemcontext = \context_system::instance();
        unassign_capability('local/forum_ai:managetenantsettings', $roleid, $systemcontext->id);
        $conditions = [
            'roleid' => $roleid,
            'capability' => 'local/forum_ai:managetenantsettings',
            'contextid' => $systemcontext->id,
        ];
        $this->assertFalse($DB->record_exists('role_capabilities', $conditions));

        $this->assertTrue(xmldb_local_forum_ai_install());

        $this->assertEquals(CAP_ALLOW, $DB->get_field('role_capabilities', 'permission', $conditions));
    }

    /**
     * Without Workplace there is no Tenant administrator role and the install still succeeds.
     */
    public function test_install_succeeds_without_tenancy(): void {
        if (class_exists('\tool_tenant\manager')) {
            $this->markTestSkipped('Only meaningful on a site without Workplace.');
        }
        $this->resetAfterTest();

        $this->assertTrue(xmldb_local_forum_ai_install());
    }
}
