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

namespace local_forum_ai\local;

/**
 * Contract between the plugin's pseudonymiser port and the AI provider's shared helper.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group     local_forum_ai
 * @coversDefaultClass \local_forum_ai\local\provider_pseudonymiser
 */
final class provider_pseudonymiser_test extends \advanced_testcase {
    /**
     * FAI-PRIV-001-R1: with nothing bound in the container, the port resolves to the
     * production adapter, so a real request never uses a fake.
     *
     * @covers \local_forum_ai\hook\di_callbacks::provide_di_configuration
     */
    public function test_container_resolves_the_provider_adapter_by_default(): void {
        \core\di::reset_container();

        $this->assertInstanceOf(provider_pseudonymiser::class, \core\di::get(userid_pseudonymiser::class));
    }

    /**
     * FAI-PRIV-001-R1: the adapter returns exactly the token the rest of the suite sends.
     *
     * @covers ::pseudonymise
     */
    public function test_adapter_returns_the_shared_suite_token(): void {
        if (!class_exists(\aiprovider_datacurso\local\outbound_privacy::class)) {
            $this->markTestSkipped('aiprovider_datacurso with outbound_privacy is not installed.');
        }
        $this->resetAfterTest();

        $adapter = new provider_pseudonymiser();
        $token = \aiprovider_datacurso\local\outbound_privacy::pseudonymise_userid(12);

        $this->assertSame($token, $adapter->pseudonymise(12));
        $this->assertSame($token, $adapter->pseudonymise('12'));
        // An already derived token is kept as is.
        $this->assertSame($token, $adapter->pseudonymise($token));
    }
}
