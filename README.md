# Rajab's Games

Rajab's Games is a Moodle block that gamifies a course built on interactive videos
(`mod_interactivevideo`): learners earn XP and badges inside the videos, climb levels, and see where they
stand on a leaderboard on the course page.

This repository is a fork of
[sokunthearithmakara/block_rajabsgames](https://github.com/sokunthearithmakara/block_rajabsgames). It
supports **Moodle 5.3 only**, on the `MOODLE_503_STABLE` branch; `CHANGELOG.md` lists what it changes
from upstream.

## Features

* Participant leaderboard: the top five participants by XP.
* Group leaderboard: the groups of a grouping and their total XP.
* Badges: teachers create badges with an image, place them in videos as a "Rajab's Games" interaction, and
  learners claim them while watching.
* Levels: XP thresholds that learners unlock in order.
* Group support: with visible groups the participant leaderboard ranks the whole grouping and shows each
  leader's groups; with separate groups it ranks only the members of the viewer's own groups.

## Requirements

* Moodle 5.3.
* `mod_interactivevideo` (Interactive video), which also provides `ivplugin_richtext`, the content type
  this plugin's interaction extends. Installing the block adds `block_rajabsgames` to Interactive video's
  enabled content types (`mod_interactivevideo/enablecontenttypes`); uninstalling removes it.

## Data and privacy

* Database tables: none of its own.
* The block's configuration (badges, levels, leaderboard and group settings) is stored in the block
  instance, and badge images in the block's `content` file area.
* The badges placed in a video are stored as an interaction in Interactive video's `interactivevideo_items`
  table. Learners' XP and badge claims are stored by Interactive video in `interactivevideo_completion`,
  through its own save path; Interactive video's privacy provider exports and deletes them.
* The course-wide leaderboards are cached per course in the application cache (`boards`) and dropped when
  progress, an enrolment or a group membership changes, or after an hour at most.
* The leaderboard shows the full name, picture and XP of the top five participants with an active enrolment
  to everyone who can see the block.

## Capabilities

* `block/rajabsgames:addinstance`: add the block to a course and edit its badges, levels and settings.
  Editing teachers and managers have it by default.

## Settings

The block has no site-wide settings. Each instance is configured from the block's own settings form.

## Installing

Install the plugin into `{your/moodle/dirroot}/blocks/rajabsgames`, either by uploading the ZIP file in
_Site administration > Plugins > Install plugins_ or by copying the files, then complete the installation
in _Site administration > Notifications_ or with `php admin/cli/upgrade.php`.

## License

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General
Public License as published by the Free Software Foundation, either version 3 of the License, or (at your
option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the
implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License
for more details.

You should have received a copy of the GNU General Public License along with this program. If not, see
<https://www.gnu.org/licenses/>.
