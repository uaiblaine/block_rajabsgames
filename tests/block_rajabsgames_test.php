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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
require_once($CFG->dirroot . '/blocks/rajabsgames/block_rajabsgames.php');

/**
 * Tests for the block's content.
 *
 * @package    block_rajabsgames
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\block_rajabsgames::class)]
final class block_rajabsgames_test extends \advanced_testcase {
    /** @var string Marker of the user leaderboard heading in leaderboard.mustache. */
    private const USERBOARD = 'alert-info m-0 text-info';

    /** @var string Marker of the group leaderboard heading in groupleaderboard.mustache. */
    private const GROUPBOARD = 'alert-danger m-0 text-danger';

    /** @var int Id of the one badge the tests configure. */
    private const BADGEID = 1700000001;

    /**
     * Adds the block to a course with the given configuration.
     *
     * @param \stdClass $course The course.
     * @param array $config Configuration values over the defaults (leaderboards, levels and badges shown).
     * @return int The block instance id.
     */
    private function add_block(\stdClass $course, array $config): int {
        $block = $this->getDataGenerator()->create_block('rajabsgames', [
            'parentcontextid' => \context_course::instance($course->id)->id,
            'configdata' => $this->configdata($config),
        ]);
        return (int)$block->id;
    }

    /**
     * Encodes a block configuration as block_instances.configdata.
     *
     * @param array $config Configuration values over the defaults (leaderboards, levels and badges shown).
     * @return string The encoded configuration.
     */
    private function configdata(array $config): string {
        $config += [
            'badges' => json_encode([['id' => self::BADGEID, 'name' => 'First', 'picture' => '']]),
            'levels' => '[]',
            'showleaderboard' => 1,
            'showlevels' => 1,
            'showbadges' => 1,
            'showgroupleaderboard' => 1,
            'groupmode' => NOGROUPS,
            'grouping' => 0,
            'timeupdated' => time() - 60,
        ];
        return base64_encode(serialize((object)$config));
    }

    /**
     * Renders the block for a user from a cold start, as a first course page view would.
     *
     * @param int $blockid Block instance id.
     * @param \stdClass $course The course.
     * @param \stdClass $user The viewer.
     * @return \block_rajabsgames The block, with its content built.
     */
    private function render(int $blockid, \stdClass $course, \stdClass $user): \block_rajabsgames {
        global $DB, $SESSION;
        unset($SESSION->{'block_rajabsgames_main_' . $blockid});
        $this->setUser($user);
        $page = new \moodle_page();
        $page->set_course($course);
        $page->set_context(\context_course::instance($course->id));
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-topics');
        $block = block_instance('rajabsgames', $DB->get_record('block_instances', ['id' => $blockid]), $page);
        $block->get_content();
        return $block;
    }

    /**
     * Gives a user XP in an activity, with the badge claimed in the given game item.
     *
     * @param int $instanceid Interactive video instance id.
     * @param int $userid The learner.
     * @param int $itemid Game item the claim belongs to.
     * @param int $xp XP of the row.
     */
    private function record_xp(int $instanceid, int $userid, int $itemid, int $xp = 10): void {
        global $DB;
        $claims = [['id' => self::BADGEID, 'name' => 'u1', 'xp' => 10]];
        $DB->insert_record('interactivevideo_completion', (object)[
            'cmid' => $instanceid,
            'userid' => $userid,
            'xp' => $xp,
            'timecreated' => time(),
            'completiondetails' => json_encode([json_encode(['id' => $itemid, 'gameprogress' => $claims])]),
        ]);
    }

    /**
     * Places the configured badge in a game item of an activity.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $iv The interactive video instance.
     * @return int The item id.
     */
    private function place_badge(\stdClass $course, \stdClass $iv): int {
        global $DB;
        return (int)$DB->insert_record('interactivevideo_items', (object)[
            'courseid' => $course->id,
            'cmid' => $iv->cmid,
            'annotationid' => $iv->id,
            'type' => 'rajabsgames',
            'xp' => 10,
            'content' => json_encode([['badgeid' => self::BADGEID, 'uniqueid' => 'u1', 'timestamp' => 1, 'xp' => 10]]),
        ]);
    }

    /**
     * Under separate groups, a viewer in no group of the grouping gets an empty board instead of a SQL error.
     */
    public function test_separate_groups_viewer_without_a_group(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $iv = $generator->create_module('interactivevideo', ['course' => $course->id]);
        $grouping = $generator->create_grouping(['courseid' => $course->id]);
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $group->id]);
        $member = $generator->create_and_enrol($course, 'student');
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
        $loner = $generator->create_and_enrol($course, 'student');
        $this->record_xp((int)$iv->id, (int)$member->id, 1);
        $blockid = $this->add_block($course, ['groupmode' => SEPARATEGROUPS, 'grouping' => $grouping->id]);

        // The member proves the board is on and has someone to rank.
        $this->assertStringContainsString(self::USERBOARD, $this->render($blockid, $course, $member)->content->text);

        $text = $this->render($blockid, $course, $loner)->content->text;
        $this->assertNotEmpty($text);
        $this->assertStringNotContainsString(self::USERBOARD, $text);
    }

    /**
     * Under visible groups, a grouping with no groups gets no group board instead of a SQL error.
     */
    public function test_visible_groups_on_an_empty_grouping(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $iv = $generator->create_module('interactivevideo', ['course' => $course->id]);
        $student = $generator->create_and_enrol($course, 'student');
        $this->record_xp((int)$iv->id, (int)$student->id, 1);

        // A grouping with a group proves the group board renders in this setup.
        $grouping = $generator->create_grouping(['courseid' => $course->id]);
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $group->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
        $blockid = $this->add_block($course, ['groupmode' => VISIBLEGROUPS, 'grouping' => $grouping->id]);
        $this->assertStringContainsString(self::GROUPBOARD, $this->render($blockid, $course, $student)->content->text);

        $empty = $generator->create_grouping(['courseid' => $course->id]);
        $DB->set_field(
            'block_instances',
            'configdata',
            $this->configdata(['groupmode' => VISIBLEGROUPS, 'grouping' => $empty->id]),
            ['id' => $blockid]
        );
        $text = $this->render($blockid, $course, $student)->content->text;
        $this->assertNotEmpty($text);
        $this->assertStringNotContainsString(self::GROUPBOARD, $text);
    }

    /**
     * Under visible groups a leader's icons name only participation groups, the ones whose membership the viewer
     * may see; an OWN-visibility group the viewer shares with the leader stays hidden.
     */
    public function test_leader_groups_follow_group_visibility(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $iv = $generator->create_module('interactivevideo', ['course' => $course->id]);
        $grouping = $generator->create_grouping(['courseid' => $course->id]);
        $open = $generator->create_group(['courseid' => $course->id, 'name' => 'Open group']);
        $own = $generator->create_group([
            'courseid' => $course->id,
            'name' => 'Own group',
            'visibility' => GROUPS_VISIBILITY_OWN,
        ]);
        $leader = $generator->create_and_enrol($course, 'student');
        $viewer = $generator->create_and_enrol($course, 'student');
        foreach ([$open, $own] as $group) {
            $generator->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $group->id]);
            $generator->create_group_member(['groupid' => $group->id, 'userid' => $leader->id]);
            $generator->create_group_member(['groupid' => $group->id, 'userid' => $viewer->id]);
        }
        // Only the leader has XP, so the viewer's own row, which may show both groups, is not on the board.
        $this->record_xp((int)$iv->id, (int)$leader->id, 1);
        $blockid = $this->add_block($course, ['groupmode' => VISIBLEGROUPS, 'grouping' => $grouping->id]);

        $text = $this->render($blockid, $course, $viewer)->content->text;

        // The leaderboard row's group icon, as leaderboard.mustache prints it.
        $icon = fn(string $name): string => '~class="rg_group_image iv-ml-2" alt="' . $name . '" width="15"~';
        $this->assertMatchesRegularExpression($icon('Open group'), $text);
        $this->assertDoesNotMatchRegularExpression($icon('Own group'), $text);
    }

    /**
     * Both boards rank only active enrolments: a suspended enrolment or an unenrolled user drops out of the
     * user board and out of the group's total, although their completion rows remain.
     */
    public function test_boards_rank_only_active_enrolments(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $iv = $generator->create_module('interactivevideo', ['course' => $course->id]);
        $grouping = $generator->create_grouping(['courseid' => $course->id]);
        $group = $generator->create_group(['courseid' => $course->id, 'name' => 'Red team']);
        $generator->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $group->id]);
        $viewer = $generator->create_and_enrol($course, 'student', ['firstname' => 'Vera', 'lastname' => 'Viewer']);
        $suspended = $generator->create_and_enrol($course, 'student', ['firstname' => 'Sam', 'lastname' => 'Suspended']);
        $gone = $generator->create_and_enrol($course, 'student', ['firstname' => 'Gil', 'lastname' => 'Gone']);
        foreach ([[$viewer, 10], [$suspended, 100], [$gone, 1000]] as [$user, $xp]) {
            $generator->create_group_member(['groupid' => $group->id, 'userid' => $user->id]);
            $this->record_xp((int)$iv->id, (int)$user->id, 1, $xp);
        }
        $blockid = $this->add_block($course, ['groupmode' => VISIBLEGROUPS, 'grouping' => $grouping->id]);
        $grouptotal = function (string $text): string {
            $board = substr($text, strpos($text, self::GROUPBOARD));
            preg_match('~Red team\s*</[^>]+>.*?(\d+\.\d{2})~s', $board, $match);
            return $match[1] ?? '';
        };

        // While all three are enrolled, both boards count them: the control.
        $text = $this->render($blockid, $course, $viewer)->content->text;
        $this->assertStringContainsString('Sam Suspended', $text);
        $this->assertStringContainsString('Gil Gone', $text);
        $this->assertSame('1110.00', $grouptotal($text));

        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manual = enrol_get_plugin('manual');
        $manual->update_user_enrol($instance, $suspended->id, ENROL_USER_SUSPENDED);
        // Unenrolling removes the group membership too; the completion row stays behind.
        $manual->unenrol_user($instance, $gone->id);
        $this->assertTrue($DB->record_exists('interactivevideo_completion', ['userid' => $gone->id]));

        $text = $this->render($blockid, $course, $viewer)->content->text;
        // The name span of leaderboard.mustache, not the picture's alt text, which carries the full name anyway.
        $this->assertMatchesRegularExpression('~class="rg_leaderboard_name[^"]*">Vera Viewer ~', $text);
        $this->assertStringNotContainsString('Sam Suspended', $text);
        $this->assertStringNotContainsString('Gil Gone', $text);
        $this->assertSame('10.00', $grouptotal($text));
    }

    /**
     * A course without interactive videos shows the notice rather than an empty, hidden block.
     */
    public function test_course_without_videos_shows_the_notice(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $blockid = $this->add_block($course, []);

        $block = $this->render($blockid, $course, $student);

        $this->assertStringContainsString(get_string('novalidinteractivevideoyet', 'block_rajabsgames'), $block->content->text);
        $this->assertFalse($block->is_empty());
    }

    /**
     * A badge claimed in two activities counts twice, even when both rows store identical details.
     */
    public function test_identical_claims_in_two_activities_count_twice(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $first = $generator->create_module('interactivevideo', ['course' => $course->id]);
        $second = $generator->create_module('interactivevideo', ['course' => $course->id]);
        $student = $generator->create_and_enrol($course, 'student');
        $this->place_badge($course, $first);
        $this->place_badge($course, $second);
        // The same item id in both rows makes the stored details byte for byte identical.
        $this->record_xp((int)$first->id, (int)$student->id, 1);
        $this->record_xp((int)$second->id, (int)$student->id, 1);
        $blockid = $this->add_block($course, []);

        $text = $this->render($blockid, $course, $student)->content->text;

        // Scoped to the counter element of badge.mustache, so a date elsewhere on the block cannot match.
        $this->assertMatchesRegularExpression('~<small class="text-muted">\s*2 / 2\s*</small>~', $text);
    }
}
