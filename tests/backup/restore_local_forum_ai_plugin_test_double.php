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

namespace local_forum_ai\backup;

defined('MOODLE_INTERNAL') || die();

/**
 * Restore test double for seeding forum AI restore mappings.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class restore_local_forum_ai_plugin_test_double extends \restore_local_forum_ai_plugin {
    /** @var array<int, array<int, int|null>> */
    private array $mappings = [];

    /**
     * Build a test double without running the parent restore constructor.
     */
    public function __construct() {
    }

    /**
     * Seed the mapping table used by the restore test double.
     *
     * @param array $mappings Mapping values by item name and source id.
     * @return void
     */
    public function seed_mappings(array $mappings): void {
        $this->mappings = $mappings;
    }

    /**
     * Seed the temporary config rows used by the restore test double.
     *
     * @param array $configs Config rows captured from backup.
     * @return void
     */
    public function seed_tempconfigs(array $configs): void {
        $this->tempconfigs = $configs;
    }

    /**
     * Return the seeded mapping value for the requested item and source id.
     *
     * @param string $itemname Mapping group name.
     * @param int $oldid Original identifier.
     * @param bool|int $ifnotfound Fallback when no mapping exists.
     * @return int|false|null
     */
    protected function get_mappingid($itemname, $oldid, $ifnotfound = false) {
        return $this->mappings[$itemname][$oldid] ?? $ifnotfound;
    }
}
