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

namespace block_rajabsgames\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * What the video player needs to run a Rajab's Games item: the course's badges and the viewer's own claims.
 *
 * Replaces the two ajax.php actions the player used to chain. The course and the user are derived on the
 * server: the course from the activity, the user from the session, so no caller can read another user's
 * progress or another course's badges.
 *
 * @package    block_rajabsgames
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_video_state extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'interactivevideoid' => new external_value(PARAM_INT, 'Interactive video instance id (not the course module id)'),
            'itemid' => new external_value(PARAM_INT, 'Id of the rajabsgames item in that activity'),
        ]);
    }

    /**
     * Returns the badges configured in the activity's course and the viewer's claims for one item.
     *
     * @param int $interactivevideoid Interactive video instance id.
     * @param int $itemid Id of the rajabsgames item.
     * @return array badges and gameprogress as JSON strings, and the block id (0 when the course has no block).
     * @throws \moodle_exception When the activity does not exist or the viewer cannot see it.
     */
    public static function execute(int $interactivevideoid, int $itemid): array {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/blocks/rajabsgames/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'interactivevideoid' => $interactivevideoid,
            'itemid' => $itemid,
        ]);

        $cm = get_coursemodule_from_instance('interactivevideo', $params['interactivevideoid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/interactivevideo:view', $context);

        $blockdata = block_rajabsgames_get_configdata((int)$cm->course);
        $blockdata = $blockdata === null ? null : (object)$blockdata;

        // The interactivevideo_completion column named cmid holds the instance id. Its (cmid, userid) index is
        // not unique, so read the newest row rather than let get_field() warn about a second one.
        $details = $DB->get_records_sql(
            'SELECT id, completiondetails
               FROM {interactivevideo_completion}
              WHERE cmid = :instance AND userid = :userid
           ORDER BY id DESC',
            ['instance' => $params['interactivevideoid'], 'userid' => $USER->id],
            0,
            1
        );
        $details = $details ? reset($details)->completiondetails : null;

        return [
            'badges' => (string)($blockdata->badges ?? '[]') ?: '[]',
            'blockid' => (int)($blockdata->id ?? 0),
            'gameprogress' => json_encode(self::extract_gameprogress($details, $params['itemid'])),
        ];
    }

    /**
     * The claims recorded for one item in a completiondetails value.
     *
     * IV stores completiondetails as a JSON array whose entries are themselves JSON-encoded objects (older
     * rows may hold the objects directly); the entry for this item carries the claims in gameprogress.
     *
     * @param string|null $completiondetails The stored column value.
     * @param int $itemid Id of the rajabsgames item.
     * @return array The claims, empty when nothing is recorded or the value cannot be read.
     */
    public static function extract_gameprogress(?string $completiondetails, int $itemid): array {
        $entries = json_decode((string)$completiondetails, true);
        if (is_string($entries)) {
            $entries = json_decode($entries, true);
        }
        if (!is_array($entries)) {
            return [];
        }
        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $entry = json_decode($entry, true);
            }
            if (is_array($entry) && isset($entry['id']) && (int)$entry['id'] === $itemid) {
                return is_array($entry['gameprogress'] ?? null) ? array_values($entry['gameprogress']) : [];
            }
        }
        return [];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'badges' => new external_value(PARAM_RAW, 'JSON array of the badges configured in the course block'),
            'blockid' => new external_value(PARAM_INT, 'Block instance id, 0 when the course has no block'),
            'gameprogress' => new external_value(PARAM_RAW, 'JSON array of the viewer\'s claims for this item'),
        ]);
    }
}
