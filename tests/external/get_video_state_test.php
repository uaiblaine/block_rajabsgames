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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the get_video_state web service.
 *
 * @package    block_rajabsgames
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_video_state::class)]
final class get_video_state_test extends \advanced_testcase {
    /** @var int Id of the game item the claims are recorded against. */
    private const ITEMID = 77;

    /**
     * A course with one interactive video and, optionally, a configured block.
     *
     * @param array|null $badges Badges stored in the block configuration; null for a course without the block.
     * @return array [course, interactivevideo instance, block id or 0]
     */
    private function create_course_with_video(?array $badges): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $iv = $generator->create_module('interactivevideo', ['course' => $course->id]);
        $blockid = 0;
        if ($badges !== null) {
            $config = (object)['badges' => json_encode($badges), 'levels' => '[]'];
            $block = $generator->create_block('rajabsgames', [
                'parentcontextid' => \context_course::instance($course->id)->id,
                'configdata' => base64_encode(serialize($config)),
            ]);
            $blockid = (int)$block->id;
        }
        return [$course, $iv, $blockid];
    }

    /**
     * Stores a completion row the way IV does: a JSON array of JSON-encoded per-item details.
     *
     * @param int $instanceid Interactive video instance id.
     * @param int $userid User the row belongs to.
     * @param array $gameprogress Claims recorded for ITEMID.
     */
    private function record_claims(int $instanceid, int $userid, array $gameprogress): void {
        global $DB;
        $DB->insert_record('interactivevideo_completion', (object)[
            'cmid' => $instanceid,
            'userid' => $userid,
            'xp' => 10,
            'timecreated' => time(),
            'completiondetails' => json_encode([json_encode(['id' => self::ITEMID, 'gameprogress' => $gameprogress])]),
        ]);
    }

    /**
     * Calls the function through the external API, as the browser does.
     *
     * @param int $instanceid Interactive video instance id.
     * @return array The external API response (error, data, exception).
     */
    private function call(int $instanceid): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('block_rajabsgames_get_video_state', [
            'interactivevideoid' => $instanceid,
            'itemid' => self::ITEMID,
        ], true);
    }

    /**
     * Each viewer receives their own claims, and only those.
     */
    public function test_returns_the_viewers_claims_only(): void {
        $this->resetAfterTest();
        [$course, $iv] = $this->create_course_with_video([]);
        $owner = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $claims = [['id' => 1700000001, 'name' => 'u1', 'xp' => 10]];
        $this->record_claims((int)$iv->id, (int)$owner->id, $claims);

        // The owner's call proves the row is readable at all.
        $this->setUser($owner);
        $response = $this->call((int)$iv->id);
        $this->assertFalse($response['error'], json_encode($response));
        $this->assertSame($claims, json_decode($response['data']['gameprogress'], true));

        // Another learner in the same activity gets nothing although the owner's row exists.
        $this->setUser($other);
        $response = $this->call((int)$iv->id);
        $this->assertFalse($response['error'], json_encode($response));
        $this->assertSame([], json_decode($response['data']['gameprogress'], true));
    }

    /**
     * A user who cannot enter the course is refused, enrolled or not elsewhere.
     */
    public function test_refuses_a_user_outside_the_course(): void {
        $this->resetAfterTest();
        [$course, $iv] = $this->create_course_with_video([['id' => 1, 'name' => 'Secret badge']]);
        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($outsider->id, $this->getDataGenerator()->create_course()->id, 'student');

        $this->setUser($outsider);
        $response = $this->call((int)$iv->id);

        $this->assertTrue($response['error']);
        $this->assertSame('requireloginerror', $response['exception']->errorcode);
    }

    /**
     * An enrolled user who may not view the activity is refused.
     */
    public function test_refuses_a_viewer_without_the_view_capability(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $iv] = $this->create_course_with_video([]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        assign_capability(
            'mod/interactivevideo:view',
            CAP_PROHIBIT,
            $studentroleid,
            \context_module::instance($iv->cmid)->id
        );

        $this->setUser($student);
        $response = $this->call((int)$iv->id);

        // Refused by validate_context(): cm_info::$uservisible already requires the module's view capability,
        // so the explicit require_capability() behind it is a second line, not the one that answers here.
        $this->assertTrue($response['error']);
        $this->assertSame('requireloginerror', $response['exception']->errorcode);
    }

    /**
     * Badges come from the activity's own course; a course without the block yields none.
     */
    public function test_badges_come_from_the_activitys_course(): void {
        $this->resetAfterTest();
        [$course, $iv, $blockid] = $this->create_course_with_video([['id' => 1, 'name' => 'Here']]);
        $this->create_course_with_video([['id' => 2, 'name' => 'Elsewhere']]);
        [$plaincourse, $plainiv] = $this->create_course_with_video(null);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $plaincourse->id, 'student');
        $this->setUser($student);

        $response = $this->call((int)$iv->id);
        $this->assertFalse($response['error'], json_encode($response));
        $this->assertSame(['Here'], array_column(json_decode($response['data']['badges'], true), 'name'));
        $this->assertSame($blockid, $response['data']['blockid']);

        $response = $this->call((int)$plainiv->id);
        $this->assertFalse($response['error'], json_encode($response));
        $this->assertSame('[]', $response['data']['badges']);
        $this->assertSame(0, $response['data']['blockid']);
    }

    /**
     * Stored shapes the parser must read, and the ones it must ignore.
     *
     * @return array
     */
    public static function completiondetails_provider(): array {
        $claims = [['id' => 5, 'name' => 'u5']];
        return [
            'entries as JSON strings' => [json_encode([json_encode(['id' => self::ITEMID, 'gameprogress' => $claims])]), $claims],
            'entries as objects' => [json_encode([['id' => self::ITEMID, 'gameprogress' => $claims]]), $claims],
            'another item only' => [json_encode([json_encode(['id' => 78, 'gameprogress' => $claims])]), []],
            'no row' => [null, []],
            'not JSON' => ['{broken', []],
        ];
    }

    /**
     * The parser finds this item's claims and nothing else.
     *
     * @param string|null $stored The completiondetails column value.
     * @param array $expected The claims expected back.
     */
    #[DataProvider('completiondetails_provider')]
    public function test_extract_gameprogress(?string $stored, array $expected): void {
        $this->assertSame($expected, get_video_state::extract_gameprogress($stored, self::ITEMID));
    }
}
