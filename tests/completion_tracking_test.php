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
require_once($CFG->dirroot . '/mod/interactivevideo/locallib.php');
require_once($CFG->dirroot . '/blocks/rajabsgames/db/upgrade.php');

/**
 * How a game item is tracked, and what a badge claim then records.
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
     * Claims the first badge the way the player does, and returns the XP stored for the item.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $iv The interactive video instance.
     * @param int $itemid The game item.
     * @return float The XP stored in the item's completion detail.
     */
    private function claim_first_badge(\stdClass $course, \stdClass $iv, int $itemid): float {
        global $DB, $USER;
        $detail = [
            'id' => $itemid,
            'xp' => 10,
            'hasDetails' => false,
            'gameprogress' => [['id' => 1, 'xp' => 10, 'name' => 'a']],
        ];
        \interactivevideo_util::save_progress(
            $iv->id,
            $USER->id,
            json_encode([$itemid]),
            json_encode($detail),
            true,
            'rajabsgames',
            '',
            0,
            50,
            0,
            0,
            10,
            true,
            $course->id
        );
        $stored = $DB->get_field('interactivevideo_completion', 'completiondetails', ['cmid' => $iv->id, 'userid' => $USER->id]);
        foreach (json_decode($stored, true) as $entry) {
            $entry = is_string($entry) ? json_decode($entry, true) : $entry;
            if ((int)$entry['id'] === $itemid) {
                return (float)$entry['xp'];
            }
        }
        $this->fail('The claim was not stored.');
    }

    /**
     * An item that earns XP is tracked as complete; one that earns none is not tracked.
     */
    public function test_the_form_tracks_scored_items_as_complete(): void {
        $this->assertSame('complete', $this->form()->pre_processing_data((object)['xp' => 20])->completiontracking);
        $this->assertSame('none', $this->form()->pre_processing_data((object)['xp' => 0])->completiontracking);
    }

    /**
     * One badge claimed in an item tracked as complete records that badge's XP, not the item's.
     */
    public function test_a_partial_claim_records_its_own_xp(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);

        // The 'manual' item is the control: there the same claim is raised to the item's full XP.
        $manual = $generator->create_module('interactivevideo', ['course' => $course->id]);
        $this->assertEquals(20, $this->claim_first_badge($course, $manual, $this->create_item($course, $manual, 'manual')));

        $iv = $generator->create_module('interactivevideo', ['course' => $course->id]);
        $tracking = $this->form()->pre_processing_data((object)['xp' => 20])->completiontracking;
        $this->assertEquals(10, $this->claim_first_badge($course, $iv, $this->create_item($course, $iv, $tracking)));
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
