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
 * Upgrade steps of the Rajab's Games block.
 *
 * @package    block_rajabsgames
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs the upgrade steps from the given version.
 *
 * @param int $oldversion The version the plugin is upgraded from.
 * @return bool
 */
function xmldb_block_rajabsgames_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2026100503) {
        // Game items saved before the form set a tracking kept the column default 'manual', for which
        // mod_interactivevideo records the item's full XP on the first badge claimed. Claims already stored
        // keep their XP: the badges they name are known, but not which XP each position carried then.
        $DB->set_field_select(
            'interactivevideo_items',
            'completiontracking',
            'complete',
            "type = :type AND hascompletion = 1 AND completiontracking IN ('manual', 'view')",
            ['type' => 'rajabsgames']
        );
        \cache_helper::purge_by_definition('mod_interactivevideo', 'iv_items_by_cmid');

        upgrade_block_savepoint(true, 2026100503, 'rajabsgames');
    }

    return true;
}
