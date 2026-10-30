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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/blocks/rajabsgames/db/upgrade.php');

/**
 * How a game item is tracked. What a claim records is tested through the claim service
 * ({@see \block_rajabsgames\external\claim_badge_test}).
 *
 * @package    block_rajabsgames
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(form::class)]
#[CoversFunction('xmldb_block_rajabsgames_upgrade')]
final class completion_tracking_test extends \advanced_testcase {
    /**
     * The interaction form, without the definition it would build from request parameters.
     *
     * @return form
     */
    private function form(): form {
        return (new \ReflectionClass(form::class))->newInstanceWithoutConstructor();
    }

    /**
     * Saves a game item worth 20 XP with two badges of 10.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $iv The interactive video instance.
     * @param string $tracking The completion tracking to store.
     * @return int The item id.
     */
    private function create_item(\stdClass $course, \stdClass $iv, string $tracking): int {
        global $DB;
        return (int)$DB->insert_record('interactivevideo_items', (object)[
            'courseid' => $course->id,
            'cmid' => $iv->cmid,
            'annotationid' => $iv->id,
            'contextid' => \context_module::instance($iv->cmid)->id,
            'type' => 'rajabsgames',
            'timestamp' => -1,
            'xp' => 20,
            'hascompletion' => 1,
            'completiontracking' => $tracking,
            'content' => json_encode([
                ['badgeid' => '1', 'uniqueid' => 'a', 'timestamp' => 3, 'xp' => 10, 'position' => 'center-center'],
                ['badgeid' => '2', 'uniqueid' => 'b', 'timestamp' => 6, 'xp' => 10, 'position' => 'center-center'],
            ]),
        ]);
    }

    /**
     * An item that earns XP is tracked as complete; one that earns none is not tracked.
     */
    public function test_the_form_tracks_scored_items_as_complete(): void {
        $this->assertSame('complete', $this->form()->pre_processing_data((object)['xp' => 20])->completiontracking);
        $this->assertSame('none', $this->form()->pre_processing_data((object)['xp' => 0])->completiontracking);
    }

    /**
     * The upgrade moves scored game items off manual and view tracking, and touches nothing else.
     */
    public function test_the_upgrade_retracks_existing_game_items(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $iv = $generator->create_module('interactivevideo', ['course' => $course->id]);
        $manual = $this->create_item($course, $iv, 'manual');
        $view = $this->create_item($course, $iv, 'view');
        $unscored = $this->create_item($course, $iv, 'manual');
        $DB->set_field('interactivevideo_items', 'hascompletion', 0, ['id' => $unscored]);
        $other = $this->create_item($course, $iv, 'manual');
        $DB->set_field('interactivevideo_items', 'type', 'richtext', ['id' => $other]);
        // The savepoint refuses to run below the version already installed.
        set_config('version', 2026100502, 'block_rajabsgames');

        xmldb_block_rajabsgames_upgrade(2026100502);

        $tracking = $DB->get_records_menu('interactivevideo_items', null, '', 'id, completiontracking');
        $this->assertSame('complete', $tracking[$manual]);
        $this->assertSame('complete', $tracking[$view]);
        $this->assertSame('manual', $tracking[$unscored]);
        $this->assertSame('manual', $tracking[$other]);
    }
}
