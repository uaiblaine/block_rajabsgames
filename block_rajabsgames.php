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
 * Block block_rajabsgames
 *
 * Documentation: {@link https://moodledev.io/docs/apis/plugintypes/blocks}
 *
 * @package    block_rajabsgames
 * @copyright  2025 Sokunthearith Makara <sokunthearithmakara@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_rajabsgames extends block_base {
    /**
     * Block initialisation
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_rajabsgames');
    }

    /**
     * Returns the formats where the block can be added.
     *
     * @return array
     */
    public function applicable_formats() {
        return ['all' => false, 'course' => true];
    }

    /**
     * Get content
     *
     * @return stdClass
     */
    public function get_content() {
        global $OUTPUT, $USER, $DB;
        $courseid = $this->page->course->id;
        $hasbadge = true;
        $haslevel = true;
        $hasleaderboard = true;

        if ($this->content !== null) {
            return $this->content;
        }

        if ($this->config == null) {
            $this->content = (object)[
                'text' => '<div class="d-flex align-items-center flex-column">'
                    . '<i class="fa fa-exclamation-triangle fa-5x mb-3"></i>'
                    . '<p class="text-muted">'  . get_string('confignotfound', 'block_rajabsgames') . '</p></div>',
            ];
            return $this->content;
        }

        // Get relevant course modules.
        $modinfo = get_fast_modinfo($courseid);
        $validcm = array_filter($modinfo->cms, function ($cm) {
            if ($cm->modname != 'interactivevideo' || $cm->deletioninprogress == 1) {
                return false;
            }
            if (!$cm->uservisible) {
                if ($cm->visible && !empty($cm->availability)) {
                    return true;
                } else {
                    return false;
                }
            } else {
                return true;
            }
            return false;
        });

        if (empty($validcm)) {
            // Set the property as well as returning it: block_base reads $this->content, not the return value.
            $this->content = (object)[
                'text' => '<div class="d-flex align-items-center flex-column">'
                    . '<i class="fa fa-exclamation-triangle fa-5x mb-3"></i>'
                    . '<p class="text-muted">'  . get_string('novalidinteractivevideoyet', 'block_rajabsgames') . '</p></div>',
            ];
            return $this->content;
        }

        // The completion table's cmid column and the items table's annotationid both hold instance ids.
        $instanceids = array_values(array_map(function ($cm) {
            return (int)$cm->instance;
        }, $validcm));
        [$ivsql, $ivparams] = $DB->get_in_or_equal($instanceids, SQL_PARAMS_NAMED, 'iv');

        // Get my XP: sum of xp from interactivevideo_completion table.
        $myxp = $DB->get_record_sql(
            "SELECT SUM(xp) AS myxp FROM {interactivevideo_completion} WHERE userid = :userid AND cmid $ivsql",
            ['userid' => $USER->id] + $ivparams
        );

        // Get total XP: sum of xp from interactivevideo_items table.
        $totalxp = $DB->get_record_sql(
            "SELECT SUM(xp) AS totalxp FROM {interactivevideo_items} WHERE courseid = :courseid AND annotationid $ivsql",
            ['courseid' => $courseid] + $ivparams
        );

        if (
            $this->config->badges
            && ($this->config->showbadges == 1 || has_capability('block/rajabsgames:addinstance', $this->context))
        ) {
            // Get badges in used.
            $usedbadges = $DB->get_records_sql(
                "SELECT id, content FROM {interactivevideo_items}
                  WHERE courseid = :courseid AND type = :ttype AND annotationid $ivsql",
                ['courseid' => $courseid, 'ttype' => 'rajabsgames'] + $ivparams
            );

            if (!$usedbadges && !has_capability('block/rajabsgames:addinstance', $this->context)) {
                // If not a teacher, don't show unused blocks.
                $badges = [];
            } else {
                $ids = [];
                foreach ($usedbadges as $ub) {
                    $ub = json_decode($ub->content, true);
                    foreach ($ub as $b) {
                        $ids[] = $b['badgeid'];
                    }
                }

                // My badges. The row id comes first: get_records_sql() keys on the first column, and two
                // activities can store identical details, which would otherwise collapse into one claim.
                $completions = $DB->get_records_sql(
                    "SELECT id, completiondetails
                       FROM {interactivevideo_completion}
                      WHERE userid = :userid AND cmid $ivsql AND " . $DB->sql_like('completiondetails', ':progress'),
                    ['userid' => $USER->id, 'progress' => '%gameprogress%'] + $ivparams
                );

                if (!$completions) {
                    $completions = [];
                    $progress = [];
                } else {
                    $progress = [];
                    foreach ($completions as $completion) {
                        $completion = json_decode($completion->completiondetails, true);
                        $completion = array_filter($completion, function ($c) {
                            return strpos($c, 'gameprogress');
                        });
                        $completion = reset($completion);
                        $completion = json_decode($completion, true);
                        $prog = $completion['gameprogress'];
                        foreach ($prog as $pro) {
                            $progress[] = $pro['id'];
                        };
                    }
                }

                $badges = file_rewrite_pluginfile_urls(
                    $this->config->badges,
                    'pluginfile.php',
                    $this->context->id,
                    'block_rajabsgames',
                    'content',
                    null
                );
                $badges = json_decode($badges, true) ?? [];
                $badges = array_map(function ($badge) use ($ids, $progress) {
                    $badge = (object)$badge;
                    $badge->isblock = true;
                    // Mine.
                    $badge->mine = array_filter($progress, function ($p) use ($badge) {
                        return $p == $badge->id;
                    });
                    $badge->mine = count($badge->mine);
                    // Total.
                    $badge->badgecount = array_filter($ids, function ($id) use ($badge) {
                        return $id == $badge->id;
                    });
                    $badge->badgecount = count($badge->badgecount);
                    $badge->unused = false;
                    if ($badge->badgecount == 0 && !has_capability('block/rajabsgames:addinstance', $this->context)) {
                        $badge->unused = true;
                    }
                    $badge->classes = $badge->mine < $badge->badgecount || $badge->badgecount == 0 ? 'filter' : '';
                    return $badge;
                }, $badges);
            }
        } else {
            $badges = [];
            $hasbadge = false;
        }

        if (
            $this->config->levels
            && ($this->config->showlevels == 1 || has_capability('block/rajabsgames:addinstance', $this->context))
        ) {
            $levels = json_decode($this->config->levels, true) ?? [];
        } else {
            $levels = [];
            $haslevel = false;
        }

        // Map completed levels based on XP.
        $levels = array_map(function ($level) use ($myxp) {
            $level = (object)$level;
            $level->completed = $level->xp <= $myxp->myxp;
            $level->isblock = true;
            return $level;
        }, $levels);

        $mylevel = 0;
        for ($i = 0; $i < count($levels); $i++) {
            if ($levels[$i]->completed) {
                $mylevel = $levels[$i]->order;
            }
        }

        $levels = array_map(function ($level) use ($mylevel) {
            $level->current = $level->order == $mylevel + 1;
            return $level;
        }, $levels);

        $datafortemplate = (array)[
            'mypicture' => $OUTPUT->user_picture($USER, ['size' => 1]),
            'myname' => fullname($USER),
            'myxp' => $myxp->myxp ?? 0,
            'mylevel' => $mylevel,
            'totalxp' => $totalxp->totalxp ?? 0,
            'totallevel' => count($levels),
            'xppercentage' => ($totalxp->totalxp == 0 ? 0 : $myxp->myxp / $totalxp->totalxp * 100),
            'levelpercentage' => (count($levels) == 0 ? 0 : $mylevel / count($levels) * 100),
            'badges' => $badges,
            'levels' => $levels,
        ];

        if ($datafortemplate['xppercentage'] == 100) {
            $datafortemplate['xpcompleted'] = true;
        }

        if ($datafortemplate['levelpercentage'] == 100) {
            $datafortemplate['levelcompleted'] = true;
        }

        // Grouping.
        $groupmode = $this->config->groupmode ?? 0;
        $grouping = $this->config->grouping ?? 0;
        $groups = [];
        $mygroups = [];
        $groupids = [];
        $mygroupids = [];
        if ($groupmode != 0 && $grouping != 0) {
            $groups = groups_get_all_groups($courseid, 0, $grouping, 'g.*', false, true);
            // The viewer's memberships come from core's per-request cache; groups_is_member() would cost two
            // queries per group.
            $mymemberships = array_flip(groups_get_user_groups($courseid, $USER->id)[0] ?? []);
            $groups = array_map(function ($group) use ($courseid, $OUTPUT, $mymemberships) {
                // Get picture if $group->picture is not 0.
                // If not hiding the group picture, and the group has a picture then use it. Fallback to generic group image.
                if ($url = get_group_picture_url($group, $courseid, true)) {
                    $group->pictureurl = $url->out();
                } else {
                    $group->pictureurl = $OUTPUT->image_url('g/g1')->out(false);
                }
                return (object)[
                    'name' => $group->name,
                    'pictureurl' => $group->pictureurl,
                    'id' => $group->id,
                    'mine' => isset($mymemberships[$group->id]),
                ];
            }, $groups);

            $mygroups = array_filter($groups, function ($group) {
                return $group->mine;
            });

            $groupids = array_map(function ($group) {
                return $group->id;
            }, $groups);

            $mygroupids = array_map(function ($group) {
                return $group->id;
            }, $mygroups);
        }

        // Both boards rank only users with an active enrolment in the course and an active account: completion
        // rows outlive an unenrolment, a suspension and a deleted account.
        $enrolled = get_enrolled_join(\context_course::instance($courseid), 'u.id', true);
        $activesql = "JOIN {user} u ON u.id = c.userid $enrolled->joins";
        $activewhere = "AND $enrolled->wheres AND u.deleted = 0 AND u.suspended = 0";

        // A grouping with no groups has nothing to rank.
        if ($groupmode == VISIBLEGROUPS && $grouping != 0 && $groupids && $this->config->showgroupleaderboard == 1) {
            $hasgroupleaderboard = true;
            $gleaderboards = $this->cached_board('groups', [$instanceids, array_values($groupids)], function () use (
                $DB,
                $groupids,
                $ivsql,
                $ivparams,
                $activesql,
                $activewhere,
                $enrolled
            ) {
                [$groupsql, $groupparams] = $DB->get_in_or_equal(array_values($groupids), SQL_PARAMS_NAMED, 'grp');
                return array_values(array_map(function ($row) {
                    // The sum as the database formats it (two decimals), which the template prints.
                    return ['groupid' => (int)$row->groupid, 'totalxp' => $row->totalxp];
                }, $DB->get_records_sql(
                    "SELECT gm.groupid, SUM(c.xp) AS totalxp
                       FROM {groups_members} gm
                       JOIN {interactivevideo_completion} c ON gm.userid = c.userid
                       $activesql
                      WHERE gm.groupid $groupsql AND c.cmid $ivsql $activewhere
                   GROUP BY gm.groupid",
                    $groupparams + $ivparams + $enrolled->params
                )));
            });
            $gleaderboards = array_map(function ($row) {
                return (object)$row;
            }, $gleaderboards);
            // Map totalxp and mine to the groups.
            $groups = array_map(function ($group) use ($gleaderboards, $groupids, $mygroupids) {
                $gleaderboard = array_filter($gleaderboards, function ($gleaderboard) use ($group) {
                    return $gleaderboard->groupid == $group->id;
                });
                if (empty($gleaderboard)) {
                    $group->totalxp = 0;
                    return $group;
                }
                $gleaderboard = reset($gleaderboard);
                $group->totalxp = $gleaderboard->totalxp;
                return $group;
            }, $groups);

            // Order the groups by totalxp.
            $groups = array_values($groups);
            usort($groups, function ($a, $b) {
                return $b->totalxp - $a->totalxp;
            });

            // Add rank.
            $i = 0;
            foreach ($groups as $group) {
                if ($i == 0) {
                    $group->rank = 1;
                } else {
                    if ($group->totalxp == $groups[$i - 1]->totalxp) {
                        $group->rank = $groups[$i - 1]->rank;
                    } else {
                        $group->rank = $i + 1;
                    }
                }
                $i++;
            };

            $datafortemplate['gleaderboard'] = $groups;
        } else {
            $hasgroupleaderboard = false;
        }

        // Leaderboard: select top 5 users by total xp from interactivevideo_completion table in the course.
        if ($this->config->showleaderboard == 1) {
            $userfields = \core_user\fields::for_userpic()->with_name()->excluding('id');
            $userfields = $userfields->get_sql('u');
            $userfields = $userfields->selects;
            // Under visible groups the board ranks every member of the grouping, under separate groups only the
            // members of the viewer's own groups. No group to rank from means an empty board, not an IN ().
            $membersql = '';
            $memberparams = [];
            $rankable = true;
            if ($groupmode == VISIBLEGROUPS || $groupmode == SEPARATEGROUPS) {
                $rankgroupids = array_values($groupmode == VISIBLEGROUPS ? $groupids : $mygroupids);
                $rankable = !empty($rankgroupids);
                if ($rankable) {
                    [$insql, $memberparams] = $DB->get_in_or_equal($rankgroupids, SQL_PARAMS_NAMED, 'rgrp');
                    $membersql = " AND u.id IN (SELECT gm.userid FROM {groups_members} gm WHERE gm.groupid $insql)";
                }
            }

            $tops = [];
            if ($rankable) {
                $buildtops = function () use (
                    $DB,
                    $userfields,
                    $ivsql,
                    $ivparams,
                    $membersql,
                    $memberparams,
                    $activesql,
                    $activewhere,
                    $enrolled
                ) {
                    return array_values(array_map(function ($row) {
                        return (array)$row;
                    }, $DB->get_records_sql(
                        "SELECT u.id $userfields, SUM(c.xp) AS totalxp
                           FROM {interactivevideo_completion} c
                           $activesql
                          WHERE c.cmid $ivsql $membersql $activewhere
                       GROUP BY u.id $userfields
                       ORDER BY totalxp DESC",
                        $ivparams + $memberparams + $enrolled->params,
                        0,
                        5
                    )));
                };
                $rankinputs = [$instanceids, $groupmode == NOGROUPS ? [] : $rankgroupids];
                $tops = $this->cached_board('users', $rankinputs, $buildtops);
                // Keyed by user id again, as the code below and get_leader_groups() expect.
                $tops = array_column(array_map(function ($row) {
                    return (object)$row;
                }, $tops), null, 'id');
            }

            if (!$tops) {
                $tops = [];
            }

            // Under separate groups the board names no groups, so there is nothing to look up.
            $theirgroupids = [];
            if ($groupmode == VISIBLEGROUPS) {
                $theirgroupids = $this->get_leader_groups(array_keys($tops), array_column($groups, 'id'));
            }

            // Map to assign rank, xp, level.
            $tops = array_map(function ($top) use ($levels, $OUTPUT, $USER, $groupmode, $grouping, $groups, $theirgroupids) {
                $top = (object)$top;
                $xp = $top->totalxp;
                $level = 1;
                $levelname = '';
                foreach ($levels as $lvl) {
                    if ($lvl->order == 1) {
                        $levelname = $lvl->name;
                    }

                    if ($xp >= $lvl->xp) {
                        $level = $lvl->order;
                        $levelname = $lvl->name;
                    }
                }
                $top->level = $level;
                $top->levelname = $levelname;
                $top->xp = $xp;
                $top->picture = $OUTPUT->user_picture($top, ['size' => 1]);
                $top->name = fullname($top);
                if ($groupmode != NOGROUPS && $grouping != 0) {
                    if ($groupmode == SEPARATEGROUPS) {
                        $theirgroups = [];
                    } else {
                        $theirgroups = array_filter($groups, function ($group) use ($top, $theirgroupids) {
                            return isset($theirgroupids[$top->id][$group->id]);
                        });
                    }
                    $theirgroups = array_values($theirgroups);
                    $top->theirgroups = array_map(function ($group) {
                        return [
                            'gname' => $group->name,
                            'gpictureurl' => $group->pictureurl,
                            'gid' => $group->id,
                        ];
                    }, $theirgroups);
                }
                if ($top->id == $USER->id) {
                    $top->isuser = true;
                }
                return $top;
            }, $tops);

            $tops = array_values($tops);

            // Remove ones where totalxp is 0.
            $tops = array_filter($tops, function ($top) {
                return $top->totalxp > 0;
            });

            for ($i = 0; $i < count($tops); $i++) {
                // Account for xp that are the same. In which case, rank is the same.
                if ($i > 0 && $tops[$i]->totalxp == $tops[$i - 1]->totalxp) {
                    $tops[$i]->rank = $tops[$i - 1]->rank;
                } else {
                    $tops[$i]->rank = $i + 1;
                }
            }

            $datafortemplate['topthree'] = $tops;
            if (count($tops) == 0) {
                $hasleaderboard = false;
            }
        } else {
            $hasleaderboard = false;
        }

        if (has_capability('block/rajabsgames:addinstance', $this->context)) {
            $hasbadge = true;
            $haslevel = true;
        }

        $datafortemplate['hasbadge'] = $hasbadge;
        $datafortemplate['haslevel'] = $haslevel;
        $datafortemplate['hasleaderboard'] = $hasleaderboard;
        $datafortemplate['hasgroupleaderboard'] = $hasgroupleaderboard;
        if ($groupmode != 0 && $grouping != 0) {
            $datafortemplate['mygroups'] = array_values($mygroups);
        }

        $this->content = (object)[
            'text' => $OUTPUT->render_from_template(
                'block_rajabsgames/main',
                $datafortemplate
            ),
        ];
        return $this->content;
    }

    /**
     * A course-wide board from the application cache, built on a miss.
     *
     * The boards depend on every learner's progress, not the viewer's, so they are shared by all viewers whose
     * inputs match: the key names the activities and groups the viewer's board is drawn from (visibility and
     * separate groups make those differ between viewers). The whole course entry is dropped whenever progress,
     * an enrolment or a group membership changes ({@see \block_rajabsgames\observer}); the definition's TTL
     * covers what no event announces, such as the IV reconciler's repairs or a renamed user.
     *
     * @param string $kind The board: 'users' or 'groups'.
     * @param array $inputs What the board is computed from, the key's other part.
     * @param callable $build Computes the board as plain arrays, on a miss.
     * @return array The board.
     */
    protected function cached_board(string $kind, array $inputs, callable $build): array {
        $cache = \cache::make('block_rajabsgames', 'boards');
        $courseid = (int)$this->page->course->id;
        $boards = $cache->get($courseid) ?: [];
        $key = $kind . '_' . sha1(json_encode($inputs));
        if (!array_key_exists($key, $boards)) {
            $boards[$key] = $build();
            $cache->set($courseid, $boards);
        }
        return $boards[$key];
    }

    /**
     * The groups each leader belongs to, among those listed, as far as the viewer may see that membership.
     *
     * One query for every leader instead of groups_is_member() per leader and group. No visibility filter is
     * needed here as long as the ids come from groups_get_all_groups(..., $participationonly = true) for the
     * viewer: participation groups have ALL or MEMBERS visibility, the viewer is only given MEMBERS groups they
     * belong to, and in both cases they may see who else is in them.
     *
     * @param array $userids Ids of the leaders.
     * @param array $groupids Ids of the groups shown by the block.
     * @return array Group ids keyed by user id then group id.
     */
    protected function get_leader_groups(array $userids, array $groupids): array {
        global $DB;
        if (!$userids || !$groupids) {
            return [];
        }
        [$usersql, $params] = $DB->get_in_or_equal(array_values($userids), SQL_PARAMS_NAMED, 'ldr');
        [$groupsql, $groupparams] = $DB->get_in_or_equal(array_values($groupids), SQL_PARAMS_NAMED, 'lgrp');
        $rows = $DB->get_recordset_sql(
            "SELECT id, userid, groupid FROM {groups_members} WHERE userid $usersql AND groupid $groupsql",
            $params + $groupparams
        );
        $memberships = [];
        foreach ($rows as $row) {
            $memberships[(int)$row->userid][(int)$row->groupid] = true;
        }
        $rows->close();
        return $memberships;
    }

    /**
     * Delete the block instance.
     *
     * @return bool
     */
    public function instance_delete() {
        global $DB;
        // Delete all files.
        $fs = get_file_storage();
        $fs->delete_area_files($this->context->id, 'block_rajabsgames');
        // Get rajabsgames items from interactivevideo_items.
        $items = $DB->get_records('interactivevideo_items', [
            'courseid' => $this->page->course->id,
            'type' => 'rajabsgames',
        ], null, 'annotationid');
        if ($items) {
            $cache = cache::make('mod_interactivevideo', 'iv_items_by_cmid');
            foreach ($items as $item) {
                // Delete cache for rebuild.
                $cmid = $item->annotationid;
                $cache->delete($cmid);
            }
        }
        // Delete the rajabsgames items from interactivevideo_items.
        $DB->delete_records('interactivevideo_items', [
            'courseid' => $this->page->course->id,
            'type' => 'rajabsgames',
        ]);
        // Clear the cache.
        $cache = \cache::make('block_rajabsgames', 'rajabsgames_configs');
        $cache->delete($this->page->course->id);
        parent::instance_delete();
        return true;
    }

    /**
     * Hide the block header.
     *
     * @return bool
     */
    public function hide_header() {
        return true;
    }

    /**
     * Loads the required JavaScript for the block.
     *
     * @return void
     */
    public function get_required_javascript() {
        global $USER;
        parent::get_required_javascript();
        // The configuration editor only where the configuration can open: on this page that is the block's
        // modal, whose fragment does not run the form template's own call. The standalone edit page has it.
        if ($this->page->user_is_editing() && has_capability('block/rajabsgames:addinstance', $this->context)) {
            $this->page->requires->js_call_amd('block_rajabsgames/block_main', 'init', [
                (int)$this->instance->id,
                (int)$this->context->id,
                (int)$this->page->course->id,
                (int)$USER->id,
            ]);
        }
    }

    /**
     * Serialize and store config data
     *
     * @param stdClass $data
     * @param bool $nolongerused
     * @return void
     */
    public function instance_config_save($data, $nolongerused = false) {
        $config = clone($data);
        // Move embedded files into a proper filearea and adjust HTML links to match.
        $config->badges = file_save_draft_area_files(
            $data->draftid,
            $this->context->id,
            'block_rajabsgames',
            'content',
            0,
            ['subdirs' => true],
            $data->badges
        );

        // Rebuilding cache.
        $cachedata = [
            'badges' => file_rewrite_pluginfile_urls(
                $config->badges,
                'pluginfile.php',
                $this->context->id,
                'block_rajabsgames',
                'content',
                null
            ),
            'levels' => $config->levels,
            'id' => $this->instance->id,
        ];

        $cache = cache::make('block_rajabsgames', 'rajabsgames_configs');
        $cache->set($this->page->course->id, $cachedata);

        parent::instance_config_save($config, $nolongerused);
    }
}
