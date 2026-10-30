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
 * The block content's refresh button: drops the figures cached in the session and reloads the page.
 *
 * Kept apart from block_rajabsgames/block_main, the configuration editor, so that a page showing the block
 * loads only this.
 *
 * @module     block_rajabsgames/refresh
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import Ajax from 'core/ajax';
import Notification from 'core/notification';

/**
 * Binds the refresh button of one block instance.
 *
 * @param {Number} blockid The block instance id
 */
export const init = (blockid) => {
    const button = document.querySelector(`#inst${blockid} .rjg-refresh`);
    if (!button) {
        return;
    }
    button.addEventListener('click', async() => {
        try {
            await Ajax.call([{methodname: 'block_rajabsgames_clear_session', args: {blockinstanceid: blockid}}])[0];
            window.location.reload();
        } catch (error) {
            Notification.exception(error);
        }
    });
};
