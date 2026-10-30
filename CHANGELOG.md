# Changelog

This fork of [sokunthearithmakara/block_rajabsgames](https://github.com/sokunthearithmakara/block_rajabsgames)
supports Moodle 5.3 only, on the `MOODLE_503_STABLE` branch. It forked from upstream `779100e` (1.2).

## 1.2+uai.1 (2026100507)

### Security
- The video player reads its state through the `block_rajabsgames_get_video_state` web service, which
  derives the course from the activity and the user from the session. It replaces `ajax.php`, whose
  `get_completiondetails` action returned any user's progress in any activity to any logged-in user,
  and whose `get_badges` action returned any course's badge configuration.
- Badge claims are graded on the server. The game type is declared server-graded, so Interactive video
  refuses its completions from the browser, and the player claims through the new
  `block_rajabsgames_claim_badge` web service. It accepts only a badge position of the item whose badge
  is still configured in the course, once, and records as the item's XP the sum of the claimed
  positions' XP as the item stores them. A learner could before send any claim list and any XP up to
  the item's total. A claim does not prove the learner reached the badge's moment in the video.

### Fixed
- Both leaderboards rank only users with an active enrolment and an active account: suspended or
  unenrolled users and deleted accounts no longer appear, nor count in their group's total. Names are
  shown with fullname(), following the site's name display settings.
- The block no longer fails with a SQL error under separate groups when the viewer is in no group of
  the grouping, or under visible groups when the grouping has no groups.
- The settings form no longer fails with a SQL error when badges exist but the course has no usable
  interactive video, and it finds used badges with one query instead of one per badge.
- A badge claimed in two activities counts twice even when both store identical details.
- A course without interactive videos shows its notice instead of hiding the block.
- A block that was added but never configured no longer raises warnings when a video reads its badges.
- Claiming one badge recorded the game item's full XP: the form left items on the default 'manual'
  tracking, for which Interactive video awards the item's whole XP on any save. Game items are now
  tracked as 'complete', so a claim records its own XP; an upgrade step moves existing items. Claims
  already stored keep the XP they were given.

### Performance
- The leaderboards are cached per course in the application cache instead of each viewer's whole render in
  the session for an hour: a viewer's own XP, level and badges are read on every render, the course-wide
  boards are dropped when Interactive video announces a progress change (its new `progress_updated` event,
  `progress_deleted`, `xp_overridden`) or an enrolment or group membership changes, and expire after an
  hour at most. The "updated every hour" line, its refresh button and the `block_rajabsgames_clear_session`
  web service are gone. Requires mod_interactivevideo 2026102900. Measured on Moodle 5.3 (300 students,
  5 videos, 10 or 100 groups): a warm render reads 4 to 7 times in 2 to 12 ms, a cold one 5 to 21, and no
  session carries the ~16 KB copy any more.
- Pages showing the block load none of its JavaScript; the configuration editor (with the modal form) is
  added in editing mode, for viewers who can configure the block.
- Group membership is read once instead of with groups_is_member() per group and per leader: a cold render
  under visible groups went from 129 / 489 / 1,209 queries (10 / 40 / 100 groups) to 15, and under
  separate groups from 25 / 85 / 205 to 5 (300 students, 5 videos, measured on Moodle 5.3).

### Plugins directory baseline
- Privacy: a null provider (the block keeps no personal data of its own; Interactive video stores the claims and
  XP), with a compliance test.
- LICENSE and COPYING.txt; lang/km is export-ignored so only English strings ship; the fleet's
  marketplace readiness test; a README that names the capability, the dependencies and what is stored where.
- CI: the fleet's ci.yml (one Moodle 5.3 job through uaiblaine/moodle-workflows, with the private
  Interactive video dependency) replaces upstream's gha.yml; phpcs and moodle-plugin-ci configuration.
- Placeholder docblocks replaced; compatibility code for Moodle 4.x removed from the session web service.

### Changed
- Supported range narrowed to Moodle 5.3 (`supported = [503, 503]`).
- Queries use `get_in_or_equal()` and the DML limit instead of concatenated id lists and `LIMIT`.
