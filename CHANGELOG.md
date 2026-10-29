# Changelog

This fork of [sokunthearithmakara/block_rajabsgames](https://github.com/sokunthearithmakara/block_rajabsgames)
supports Moodle 5.3 only, on the `MOODLE_503_STABLE` branch. It forked from upstream `779100e` (1.2).

## 1.2+uai.1 (2026100501)

### Security
- The video player reads its state through the `block_rajabsgames_get_video_state` web service, which
  derives the course from the activity and the user from the session. It replaces `ajax.php`, whose
  `get_completiondetails` action returned any user's progress in any activity to any logged-in user,
  and whose `get_badges` action returned any course's badge configuration.

### Fixed
- The block no longer fails with a SQL error under separate groups when the viewer is in no group of
  the grouping, or under visible groups when the grouping has no groups.
- The settings form no longer fails with a SQL error when badges exist but the course has no usable
  interactive video, and it finds used badges with one query instead of one per badge.
- A badge claimed in two activities counts twice even when both store identical details.
- A course without interactive videos shows its notice instead of hiding the block.
- A block that was added but never configured no longer raises warnings when a video reads its badges.

### Changed
- Supported range narrowed to Moodle 5.3 (`supported = [503, 503]`).
- Queries use `get_in_or_equal()` and the DML limit instead of concatenated id lists and `LIMIT`.
