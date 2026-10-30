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
 * Claims one badge placed in a Rajab's Games item for the viewer, graded on the server.
 *
 * The type is server-graded ({@see \block_rajabsgames\main::$servergraded}), so Interactive video refuses
 * its completions from the browser and this is the only way a claim is recorded. The badge must be a
 * position of the item, its badge must still be configured in the course block, and it can be claimed
 * once; the item's XP is the sum of the claimed positions' XP as the item stores them. It does not check
 * that the viewer reached the position's timestamp: Interactive video records no trusted watch position.
 *
 * @package    block_rajabsgames
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class claim_badge extends external_api {
    /** @var int Seconds to wait for another claim of the same learner in the same activity to finish. */
    private const LOCK_TIMEOUT = 5;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'interactivevideoid' => new external_value(PARAM_INT, 'Interactive video instance id (not the course module id)'),
            'itemid' => new external_value(PARAM_INT, 'Id of the rajabsgames item in that activity'),
            'uniqueid' => new external_value(PARAM_RAW, 'Unique id of the badge position in the item, as the teacher typed it'),
        ]);
    }

    /**
     * Records the claim and returns the viewer's claims and progress.
     *
     * A guest's claim is accepted and not recorded, as Interactive video records nothing for a guest.
     *
     * @param int $interactivevideoid Interactive video instance id.
     * @param int $itemid Id of the rajabsgames item.
     * @param string $uniqueid Unique id of the badge position.
     * @return array recorded, claimed, completed, xp, gameprogress and progress; see execute_returns().
     * @throws \invalid_parameter_exception When the item is not this activity's game item, or the position is
     *      not one of its badges still configured in the course.
     * @throws \moodle_exception When the viewer cannot see the activity, or the claim lock does not come.
     */
    public static function execute(int $interactivevideoid, int $itemid, string $uniqueid): array {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/blocks/rajabsgames/lib.php');
        require_once($CFG->dirroot . '/mod/interactivevideo/locallib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'interactivevideoid' => $interactivevideoid,
            'itemid' => $itemid,
            'uniqueid' => $uniqueid,
        ]);
        $instanceid = (int)$params['interactivevideoid'];

        $cm = get_coursemodule_from_instance('interactivevideo', $instanceid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/interactivevideo:view', $context);

        // The item is read by its own id and must belong to the activity that authorised the call.
        $item = $DB->get_record('interactivevideo_items', [
            'id' => $params['itemid'],
            'annotationid' => $instanceid,
            'type' => 'rajabsgames',
        ]);
        if (!$item) {
            throw new \invalid_parameter_exception('unknown game item for this activity');
        }
        $positions = self::claimable_positions($item, (int)$cm->course);
        if (!array_key_exists($params['uniqueid'], $positions)) {
            throw new \invalid_parameter_exception('unknown badge position');
        }

        if (isguestuser() || (int)$USER->id <= 1) {
            $claimed = [$params['uniqueid']];
            return self::answer(false, true, $positions, $claimed, null);
        }

        // Read, decide and record under one lock: two claims racing would each append to the same old list.
        // Interactive video's own completion lock is a different resource, taken inside record_graded_completion().
        $lock = \core\lock\lock_config::get_lock_factory('block_rajabsgames_claim')
            ->get_lock($instanceid . '_' . (int)$USER->id, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('locktimeout', 'mod_interactivevideo');
        }
        try {
            $stored = \interactivevideo_util::read_from_primary(function () use ($DB, $instanceid, $USER) {
                $rows = $DB->get_records_sql(
                    'SELECT id, completiondetails
                       FROM {interactivevideo_completion}
                      WHERE cmid = :instance AND userid = :userid
                   ORDER BY id DESC',
                    ['instance' => $instanceid, 'userid' => $USER->id],
                    0,
                    1
                );
                return $rows ? reset($rows)->completiondetails : null;
            }, 'interactivevideo_completion');
            $claimed = array_column(get_video_state::extract_gameprogress($stored, (int)$item->id), 'name');
            // Only positions the item still has count; a position the teacher removed is dropped from the list.
            $claimed = array_values(array_intersect(array_map('strval', $claimed), array_keys($positions)));
            if (in_array($params['uniqueid'], $claimed, true)) {
                return self::answer(true, false, $positions, $claimed, null);
            }
            $claimed[] = $params['uniqueid'];

            $gameprogress = self::gameprogress($positions, $claimed);
            $xp = array_sum(array_column($gameprogress, 'xp'));
            $complete = count($claimed) === count($positions);
            $progress = \interactivevideo_util::record_graded_completion(
                $instanceid,
                (int)$USER->id,
                (int)$item->id,
                [
                    'xp' => $xp,
                    'gameprogress' => $gameprogress,
                    'timecompleted' => time() * 1000,
                    'reportView' => self::report_view($xp, $complete),
                ],
                json_encode(['gameprogress' => $gameprogress]),
                true,
                true
            );
        } finally {
            $lock->release();
        }
        return self::answer(true, true, $positions, $claimed, $progress);
    }

    /**
     * The item's badge positions a learner can claim, keyed by unique id.
     *
     * The player hides a position whose badge is no longer configured in the course block, so it cannot
     * be claimed either.
     *
     * @param \stdClass $item The interactivevideo_items row.
     * @param int $courseid The activity's course.
     * @return array Unique id => ['badgeid' => int, 'xp' => float], in the item's order.
     */
    private static function claimable_positions(\stdClass $item, int $courseid): array {
        $config = block_rajabsgames_get_configdata($courseid);
        $badges = json_decode((string)(((object)($config ?? []))->badges ?? '[]'), true);
        $badgeids = is_array($badges) ? array_map('strval', array_column($badges, 'id')) : [];

        $content = json_decode((string)$item->content, true);
        $positions = [];
        foreach (is_array($content) ? $content : [] as $position) {
            if (!is_array($position) || !isset($position['uniqueid'], $position['badgeid'])) {
                continue;
            }
            if (!in_array((string)$position['badgeid'], $badgeids, true)) {
                continue;
            }
            $positions[(string)$position['uniqueid']] = [
                'badgeid' => (int)$position['badgeid'],
                'xp' => max(0.0, (float)($position['xp'] ?? 0)),
            ];
        }
        return $positions;
    }

    /**
     * The claim list stored in the completion detail, in the shape the player reads.
     *
     * @param array $positions What claimable_positions() returned.
     * @param array $claimed Unique ids claimed, each a key of $positions.
     * @return array One ['id' => badge id, 'xp' => XP, 'name' => unique id] per claim, in the item's order.
     */
    private static function gameprogress(array $positions, array $claimed): array {
        $list = [];
        foreach ($positions as $uniqueid => $position) {
            if (in_array((string)$uniqueid, $claimed, true)) {
                $list[] = ['id' => $position['badgeid'], 'xp' => $position['xp'], 'name' => (string)$uniqueid];
            }
        }
        return $list;
    }

    /**
     * The report cell for the item, as the player used to send it: the date in a tooltip, a check when every
     * badge is claimed, and the XP.
     *
     * @param float $xp XP the item now carries.
     * @param bool $complete Whether every badge of the item is claimed.
     * @return string The cell's markup.
     */
    private static function report_view(float $xp, bool $complete): string {
        $icon = $complete ? 'check text-success' : 'circle-o text-secondary';
        $date = s(userdate(time()));
        return '<span data-bs-toggle="tooltip" data-bs-html="true" title=\'<span class="d-flex flex-column '
            . 'align-items-start"><span><i class="bi bi-calendar iv-mr-2"></i> ' . $date . '</span></span>\'>'
            . '<i class="fa fa-' . $icon . '"></i><br><span>' . format_float($xp, -1) . '</span></span>';
    }

    /**
     * The response.
     *
     * @param bool $recorded Whether the claim list is stored (false for a guest).
     * @param bool $claimed Whether this call added the claim (false when it was already claimed).
     * @param array $positions What claimable_positions() returned.
     * @param array $claimedids Unique ids now claimed.
     * @param \stdClass|null $progress The progress record record_graded_completion() returned, when it ran.
     * @return array As execute_returns() describes.
     */
    private static function answer(
        bool $recorded,
        bool $claimed,
        array $positions,
        array $claimedids,
        ?\stdClass $progress
    ): array {
        $gameprogress = self::gameprogress($positions, $claimedids);
        return [
            'recorded' => $recorded,
            'claimed' => $claimed,
            'completed' => count($claimedids) === count($positions),
            'xp' => (float)array_sum(array_column($gameprogress, 'xp')),
            'gameprogress' => json_encode($gameprogress),
            'progress' => $progress === null ? '' : json_encode($progress),
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'recorded' => new external_value(PARAM_BOOL, 'Whether the claims are stored; false for a guest'),
            'claimed' => new external_value(PARAM_BOOL, 'Whether this call added the claim; false when already claimed'),
            'completed' => new external_value(PARAM_BOOL, 'Whether every badge of the item is now claimed'),
            'xp' => new external_value(PARAM_FLOAT, 'XP the item now carries for the viewer'),
            'gameprogress' => new external_value(PARAM_RAW, 'JSON array of the viewer\'s claims for this item'),
            'progress' => new external_value(PARAM_RAW, 'JSON of the activity progress record; empty when nothing was recorded'),
        ]);
    }
}
