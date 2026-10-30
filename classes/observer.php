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

namespace block_rajabsgames;

/**
 * Drops a course's cached leaderboards when something they are computed from changes.
 *
 * The boards rank active enrolments by the XP stored in mod_interactivevideo's completion rows, split by
 * group, so a saved, deleted or overridden progress, an enrolment change and a group membership change all
 * reach them. Each event names its course; dropping that course's entry is enough.
 *
 * @package    block_rajabsgames
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Drops the boards of the event's course.
     *
     * @param \core\event\base $event Any of the events db/events.php routes here.
     * @return void
     */
    public static function invalidate_boards(\core\event\base $event): void {
        if (!empty($event->courseid)) {
            \cache::make('block_rajabsgames', 'boards')->delete((int)$event->courseid);
        }
    }
}
