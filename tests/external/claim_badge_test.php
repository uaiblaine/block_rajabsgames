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
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/interactivevideo/locallib.php');

/**
 * Tests for the claim_badge web service, the only way a badge claim is recorded.
 *
 * @package    block_rajabsgames
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(claim_badge::class)]
#[CoversClass(\block_rajabsgames\main::class)]
final class claim_badge_test extends \advanced_testcase {
    /**
     * A course whose block configures the given badges, with one interactive video holding a game item
     * worth 20 XP: position 'a' places badge 1 and position 'b' badge 2, 10 XP each.
     *
     * @param array $badgeids Badge ids configured in the course block.
     * @return array [course, interactivevideo instance, item id]
     */
    private function create_game(array $badgeids = [1, 2]): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $config = (object)[
            'badges' => json_encode(array_map(fn($id) => ['id' => $id, 'name' => "Badge $id", 'picture' => ''], $badgeids)),
            'levels' => '[]',
        ];
        $generator->create_block('rajabsgames', [
            'parentcontextid' => \context_course::instance($course->id)->id,
            'configdata' => base64_encode(serialize($config)),
        ]);
        $iv = $generator->create_module('interactivevideo', ['course' => $course->id]);
        $itemid = (int)$DB->insert_record('interactivevideo_items', (object)[
            'courseid' => $course->id,
            'cmid' => $iv->cmid,
            'annotationid' => $iv->id,
            'contextid' => \context_module::instance($iv->cmid)->id,
            'type' => 'rajabsgames',
            'timestamp' => -1,
            'xp' => 20,
            'hascompletion' => 1,
            'completiontracking' => 'complete',
            'content' => json_encode([
                ['badgeid' => '1', 'uniqueid' => 'a', 'timestamp' => 3, 'xp' => 10, 'position' => 'center-center'],
                ['badgeid' => '2', 'uniqueid' => 'b', 'timestamp' => 6, 'xp' => 10, 'position' => 'center-center'],
            ]),
        ]);
        return [$course, $iv, $itemid];
    }

    /**
     * Calls the function through the external API, as the browser does.
     *
     * @param int $instanceid Interactive video instance id.
     * @param int $itemid Game item id.
     * @param string $uniqueid Badge position.
     * @return array The external API response (error, data, exception).
     */
    private function claim(int $instanceid, int $itemid, string $uniqueid): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('block_rajabsgames_claim_badge', [
            'interactivevideoid' => $instanceid,
            'itemid' => $itemid,
            'uniqueid' => $uniqueid,
        ], true);
    }

    /**
     * The learner's stored completion detail for the item, or null.
     *
     * @param int $instanceid Interactive video instance id.
     * @param int $userid The learner.
     * @param int $itemid Game item id.
     * @return array|null The detail, decoded.
     */
    private function stored_detail(int $instanceid, int $userid, int $itemid): ?array {
        global $DB;
        $details = $DB->get_field('interactivevideo_completion', 'completiondetails', ['cmid' => $instanceid, 'userid' => $userid]);
        foreach (json_decode((string)$details, true) ?: [] as $entry) {
            $entry = is_string($entry) ? json_decode($entry, true) : $entry;
            if ((int)$entry['id'] === $itemid) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Each claim records its position, and the item's XP is the sum of the claimed positions' XP.
     */
    public function test_claims_record_the_positions_and_their_xp(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $iv, $itemid] = $this->create_game();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $sink = $this->redirectEvents();
        $response = $this->claim((int)$iv->id, $itemid, 'a');
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_interactivevideo\event\progress_updated);
        $sink->close();
        $this->assertFalse($response['error'], json_encode($response));
        $this->assertTrue($response['data']['recorded']);
        $this->assertTrue($response['data']['claimed']);
        $this->assertFalse($response['data']['completed']);
        $this->assertEquals(10, $response['data']['xp']);
        $this->assertCount(1, $events);

        $detail = $this->stored_detail((int)$iv->id, (int)$student->id, $itemid);
        $this->assertEquals(10, $detail['xp']);
        $this->assertSame(['a'], array_column($detail['gameprogress'], 'name'));
        $this->assertSame([1], array_column($detail['gameprogress'], 'id'));
        $row = $DB->get_record('interactivevideo_completion', ['cmid' => $iv->id, 'userid' => $student->id]);
        $this->assertContains((string)$itemid, json_decode($row->completeditems, true));

        $response = $this->claim((int)$iv->id, $itemid, 'b');
        $this->assertFalse($response['error'], json_encode($response));
        $this->assertTrue($response['data']['completed']);
        $this->assertEquals(20, $response['data']['xp']);
        $detail = $this->stored_detail((int)$iv->id, (int)$student->id, $itemid);
        $this->assertEquals(20, $detail['xp']);
        $this->assertSame(['a', 'b'], array_column($detail['gameprogress'], 'name'));
        $this->assertEquals(20, json_decode($response['data']['progress'], true)['xp']);
    }

    /**
     * A badge already claimed is not claimed again, and the XP does not grow.
     */
    public function test_a_badge_is_claimed_once(): void {
        $this->resetAfterTest();
        [$course, $iv, $itemid] = $this->create_game();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->assertTrue($this->claim((int)$iv->id, $itemid, 'a')['data']['claimed']);
        $response = $this->claim((int)$iv->id, $itemid, 'a');

        $this->assertFalse($response['error'], json_encode($response));
        $this->assertFalse($response['data']['claimed']);
        $this->assertEquals(10, $response['data']['xp']);
        $detail = $this->stored_detail((int)$iv->id, (int)$student->id, $itemid);
        $this->assertEquals(10, $detail['xp']);
        $this->assertSame(['a'], array_column($detail['gameprogress'], 'name'));
    }

    /**
     * A position the item does not have is refused, and nothing is written.
     */
    public function test_an_unknown_position_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $iv, $itemid] = $this->create_game();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $response = $this->claim((int)$iv->id, $itemid, 'invented');

        $this->assertTrue($response['error']);
        $this->assertSame('invalidparameter', $response['exception']->errorcode);
        $this->assertFalse($DB->record_exists('interactivevideo_completion', ['cmid' => $iv->id, 'userid' => $student->id]));
    }

    /**
     * A position whose badge the course block no longer configures cannot be claimed; one whose badge it
     * does can.
     */
    public function test_a_badge_missing_from_the_block_is_refused(): void {
        $this->resetAfterTest();
        [$course, $iv, $itemid] = $this->create_game([1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $response = $this->claim((int)$iv->id, $itemid, 'b');
        $this->assertTrue($response['error']);
        $this->assertSame('invalidparameter', $response['exception']->errorcode);

        $response = $this->claim((int)$iv->id, $itemid, 'a');
        $this->assertFalse($response['error'], json_encode($response));
        // Badge 2 is gone, so badge 1 alone completes the item.
        $this->assertTrue($response['data']['completed']);
    }

    /**
     * The item must be a game item of the activity that authorised the call.
     *
     * Interactive video refuses an interaction of another activity too, with the same exception class, so the
     * refusal is told apart by its debug info: this service answers before anything reaches Interactive video.
     */
    public function test_an_item_of_another_activity_or_type_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $iv, $itemid] = $this->create_game();
        $other = $this->getDataGenerator()->create_module('interactivevideo', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        // Another activity, then a rich text item of this activity whose content has the same positions.
        $richtext = $DB->get_record('interactivevideo_items', ['id' => $itemid]);
        unset($richtext->id);
        $richtext->type = 'richtext';
        $richtextid = (int)$DB->insert_record('interactivevideo_items', $richtext);
        foreach ([[(int)$other->id, $itemid], [(int)$iv->id, $richtextid]] as [$instanceid, $refused]) {
            try {
                claim_badge::execute($instanceid, $refused, 'a');
                $this->fail("Item {$refused} was claimed through activity {$instanceid}.");
            } catch (\invalid_parameter_exception $e) {
                $this->assertSame('unknown game item for this activity', $e->debuginfo);
            }
        }

        // The game item through its own activity is accepted.
        $this->assertTrue(claim_badge::execute((int)$iv->id, $itemid, 'a')['claimed']);
    }

    /**
     * An enrolled learner who may not view the activity is refused.
     */
    public function test_a_learner_without_the_view_capability_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $iv, $itemid] = $this->create_game();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        assign_capability(
            'mod/interactivevideo:view',
            CAP_PROHIBIT,
            (int)$DB->get_field('role', 'id', ['shortname' => 'student']),
            \context_module::instance($iv->cmid)->id
        );
        $this->setUser($student);

        $response = $this->claim((int)$iv->id, $itemid, 'a');

        // The course module's visibility already reads the view capability, so require_login() answers first;
        // the explicit require_capability() behind it is the second line.
        $this->assertTrue($response['error']);
        $this->assertSame('requireloginerror', $response['exception']->errorcode);
        $this->assertFalse($DB->record_exists('interactivevideo_completion', ['cmid' => $iv->id]));
    }

    /**
     * A user who cannot enter the course is refused.
     */
    public function test_a_user_outside_the_course_is_refused(): void {
        $this->resetAfterTest();
        [, $iv, $itemid] = $this->create_game();
        $this->setUser($this->getDataGenerator()->create_user());

        $response = $this->claim((int)$iv->id, $itemid, 'a');

        $this->assertTrue($response['error']);
        $this->assertSame('requireloginerror', $response['exception']->errorcode);
    }

    /**
     * A guest's claim is answered and not recorded.
     */
    public function test_a_guest_claim_is_not_recorded(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $iv, $itemid] = $this->create_game();
        $guest = enrol_get_plugin('guest');
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'guest'], '*', MUST_EXIST);
        $guest->update_status($instance, ENROL_INSTANCE_ENABLED);
        $this->setGuestUser();

        $response = $this->claim((int)$iv->id, $itemid, 'a');

        $this->assertFalse($response['error'], json_encode($response));
        $this->assertFalse($response['data']['recorded']);
        $this->assertSame('', $response['data']['progress']);
        $this->assertFalse($DB->record_exists('interactivevideo_completion', ['cmid' => $iv->id]));
    }

    /**
     * The type is server-graded: Interactive video refuses its completion from the browser's save.
     */
    public function test_the_browser_save_is_refused(): void {
        $this->resetAfterTest();
        [$course, $iv, $itemid] = $this->create_game();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $this->assertTrue(\interactivevideo_util::is_server_graded_type('rajabsgames'));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('servergradeditem', 'mod_interactivevideo'));
        \interactivevideo_util::save_progress(
            $iv->id,
            $student->id,
            json_encode([$itemid]),
            json_encode(['id' => $itemid, 'xp' => 20, 'gameprogress' => []]),
            true,
            'rajabsgames',
            '',
            1,
            100,
            100,
            0,
            20,
            true,
            $course->id
        );
    }
}
