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
 * Event observers of the Rajab's Games block: what drops the cached leaderboards.
 *
 * @package    block_rajabsgames
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_interactivevideo\event\progress_updated',
        'callback' => '\block_rajabsgames\observer::invalidate_boards',
    ],
    [
        'eventname' => '\mod_interactivevideo\event\progress_deleted',
        'callback' => '\block_rajabsgames\observer::invalidate_boards',
    ],
    [
        'eventname' => '\mod_interactivevideo\event\xp_overridden',
        'callback' => '\block_rajabsgames\observer::invalidate_boards',
    ],
    [
        'eventname' => '\core\event\user_enrolment_updated',
        'callback' => '\block_rajabsgames\observer::invalidate_boards',
    ],
    [
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => '\block_rajabsgames\observer::invalidate_boards',
    ],
    [
        'eventname' => '\core\event\group_member_added',
        'callback' => '\block_rajabsgames\observer::invalidate_boards',
    ],
    [
        'eventname' => '\core\event\group_member_removed',
        'callback' => '\block_rajabsgames\observer::invalidate_boards',
    ],
    [
        'eventname' => '\core\event\group_deleted',
        'callback' => '\block_rajabsgames\observer::invalidate_boards',
    ],
    [
        'eventname' => '\core\event\grouping_group_assigned',
        'callback' => '\block_rajabsgames\observer::invalidate_boards',
    ],
    [
        'eventname' => '\core\event\grouping_group_unassigned',
        'callback' => '\block_rajabsgames\observer::invalidate_boards',
    ],
];
