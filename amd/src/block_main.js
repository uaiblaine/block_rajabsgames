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
 * The levels and badges editor of the block's configuration form. A page shows the block without loading
 * this module unless it is in editing mode.
 *
 * Every handler is delegated from the document under the click.rajabsgames namespace and to selectors
 * inside the plugin's roots (.block-rajabsgames), so it neither removes nor catches other plugins' handlers.
 *
 * @module     block_rajabsgames/block_main
 * @copyright  2025 Sokunthearith Makara <sokunthearithmakara@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import $ from 'jquery';
import ModalForm from 'core_form/modalform';
import Template from 'core/templates';

/** @var {boolean} initialised Whether init() has already bound the handlers on this page. */
let initialised = false;

/**
 * The configuration form's root. On the course page the block's own lists sit behind the form's modal and
 * carry the same classes, so every lookup the editor makes is confined to the form.
 * @var {String} CONFIG
 */
const CONFIG = '.block-rajabsgames-config';

/**
 * Parses rendered badge or level items for the configuration form's lists, giving each the listitem role that
 * the list templates set through their itemrole block.
 *
 * @param {String} html The rendered block_rajabsgames/badge or block_rajabsgames/level template
 * @returns {jQuery} The parsed nodes
 */
const asListItems = (html) => $($.parseHTML(html)).filter('.block-rajabsgames').attr('role', 'listitem').end();

/**
 * The configuration form's item of one badge, matched on its id without building a selector from it.
 *
 * @param {Number|String} badgeid The badge id
 * @returns {jQuery} The badge's list item, if any
 */
const configBadgeItem = (badgeid) => $(CONFIG + ' .rg_badge_item').filter(function() {
    return this.getAttribute('data-id') === String(badgeid);
});

/**
 * Binds the configuration form's handlers, once per page.
 *
 * Called by the block's get_required_javascript() on a page in editing mode, where the configuration opens in
 * a modal, and by the configuration form template on the standalone edit page. The first call wins: the
 * handlers are delegated, so they serve a form rendered again.
 *
 * @param {Number} blockid The block instance id
 * @param {Number} contextid The block's context id
 * @param {Number} courseid The course id
 * @param {Number} userid The current user's id
 * @returns {Promise<void>}
 */
export const init = async(blockid, contextid, courseid, userid) => {
    if (initialised) {
        return;
    }
    initialised = true;
    let string = await import('core/str');

    const bindEvents = (event, selector, func) => {
        const namespaced = event + '.rajabsgames';
        const scoped = '.block-rajabsgames ' + selector;
        $(document).off(namespaced, scoped).on(namespaced, scoped, func);
    };

    bindEvents('click', '#add-badge', async function() {
        // Open the modal to add a badge.
        let data = {
            contextid,
            courseid,
            userid,
            blockid
        };

        const form = new ModalForm({
            modalConfig: {
                title: await string.get_string('addbadge', 'block_rajabsgames'),
            },
            formClass: "block_rajabsgames\\badge_form",
            args: data,
        });

        form.show();

        form.addEventListener(form.events.FORM_SUBMITTED, async(event) => {
            $(CONFIG + ' .rg_badges_list .empty-message').remove();
            let response = event.detail;
            let badge = await Template.render('block_rajabsgames/badge', {
                badges: [
                    {
                        id: response.badgeid,
                        name: response.badge_name,
                        picture: response.url,
                    }
                ],
            });
            // Append the new badge to the badges list.
            $(CONFIG + ' .rg_badges_list').append(asListItems(badge));
            let badges = JSON.parse($('[name=config_badges]').val() || '[]');
            // Add the new badge to the badges array.
            if (!Array.isArray(badges)) {
                badges = [];
            }
            badges.push({
                id: response.badgeid,
                name: response.badge_name,
                picture: response.url,
            });
            // Update the hidden input with the new badges array.
            $('[name=config_badges]').val(JSON.stringify(badges));
        });
    });

    bindEvents('click', '.delete-badge', async function() {
        // Delete the badge.
        let badgeid = $(this).data('id');
        configBadgeItem(badgeid).remove();
        // Update the config_badges.
        let badges = JSON.parse($('[name=config_badges]').val() || '[]');
        badges = badges.filter(badge => badge.id !== badgeid);
        $('[name=config_badges]').val(JSON.stringify(badges));
    });

    bindEvents('click', '.edit-badge', async function() {
        // Open the modal to edit a badge.
        let badgeid = $(this).data('id');
        let badges = JSON.parse($('[name=config_badges]').val() || '[]');
        let badge = badges.find(badge => badge.id === badgeid);
        let url = badge.picture;
        let used = $(this).data('used');
        let draftid = url.match(/user\/draft\/(\d+)/);
        if (draftid) {
            draftid = draftid[1];
        } else {
            draftid = '';
        }
        let data = {
            contextid,
            courseid,
            userid,
            blockid,
            badgeid: badge.id,
            "badge_name": badge.name,
            url,
            draftid,
        };

        const form = new ModalForm({
            modalConfig: {
                title: await string.get_string('addbadge', 'block_rajabsgames'),
            },
            formClass: "block_rajabsgames\\badge_form",
            args: data,
        });

        form.show();

        form.addEventListener(form.events.FORM_SUBMITTED, async(event) => {

            let response = event.detail;
            // Update the badge.
            let badgedata = {
                id: response.badgeid,
                name: response.badge_name,
                picture: response.url,
            };
            badges = badges.filter(badge => badge.id !== badgeid);
            badges.push(badgedata);
            // Sort badges by id.
            badges.sort((a, b) => a.id - b.id);
            $('[name=config_badges]').val(JSON.stringify(badges));

            // Replace the badge in the UI.
            badgedata.used = used;
            let badge = await Template.render('block_rajabsgames/badge', {
                badges: [
                    badgedata
                ],
            });
            configBadgeItem(badgeid).replaceWith(asListItems(badge));

        });
    });

    bindEvents('click', '#add-level', async function() {
        // Open the modal to add a level.
        let data = {
            contextid,
            courseid,
            userid,
            blockid,
            order: 1
        };

        let form = new ModalForm({
            modalConfig: {
                title: await string.get_string('addlevel', 'block_rajabsgames'),
            },
            formClass: "block_rajabsgames\\level_form",
            args: data,
        });

        form.show();

        form.addEventListener(form.events.FORM_SUBMITTED, async(event) => {

            let response = event.detail;
            let leveldata = {
                id: response.levelid,
                name: response.level_name,
                order: response.order,
                xp: response.xplimit
            };

            let levels = JSON.parse($('[name=config_levels]').val() || '[]');
            // Add the new level to the levels array.
            if (!Array.isArray(levels)) {
                levels = [];
            }
            levels.push(leveldata);
            // Sort the levels array by order.
            levels.sort((a, b) => a.xp - b.xp);
            // Change order value.
            for (let i = 0; i < levels.length; i++) {
                levels[i].order = i + 1;
            }
            let level = await Template.render('block_rajabsgames/level', {
                levels,
            });
            // Append the new level to the levels list.
            $(CONFIG + ' .rg_level_list').empty().append(asListItems(level));
            // Update the hidden input with the new badges array.
            $('[name=config_levels]').val(JSON.stringify(levels));
        });
    });

    bindEvents('click', '.delete-level', async function() {
        // Delete the level.
        let levelid = $(this).data('id');
        // Update the config_levels.
        let levels = JSON.parse($('[name=config_levels]').val() || '[]');
        levels = levels.filter(level => level.id !== levelid);
        // Sort the levels array by order.
        levels.sort((a, b) => a.order - b.order);
        // Change order value.
        for (let i = 0; i < levels.length; i++) {
            levels[i].order = i + 1;
        }
        let level = await Template.render('block_rajabsgames/level', {
            levels,
        });
        // Append the new level to the levels list.
        $(CONFIG + ' .rg_level_list').empty().append(asListItems(level));
        $('[name=config_levels]').val(JSON.stringify(levels));
    });

    bindEvents('click', '.edit-level', async function() {
        // Open the modal to edit a level.
        let levelid = $(this).data('id');
        let levels = JSON.parse($('[name=config_levels]').val() || '[]');
        let level = levels.find(level => level.id === levelid);
        let data = {
            contextid,
            courseid,
            userid,
            blockid,
            order: level.order,
            levelid: level.id,
            "level_name": level.name,
            xplimit: level.xp,
        };

        let form = new ModalForm({
            modalConfig: {
                title: await string.get_string('editlevel', 'block_rajabsgames'),
            },
            formClass: "block_rajabsgames\\level_form",
            args: data,
        });

        form.show();

        form.addEventListener(form.events.FORM_SUBMITTED, async(event) => {
            let response = event.detail;
            levels = levels.filter(level => level.id !== levelid);
            let leveldata = {
                id: response.levelid,
                name: response.level_name,
                order: response.order,
                xp: response.xplimit
            };
            levels.push(leveldata);
            // Sort the levels array by order.
            levels.sort((a, b) => a.xp - b.xp);
            // Change order value.
            for (let i = 0; i < levels.length; i++) {
                levels[i].order = i + 1;
            }
            let level = await Template.render('block_rajabsgames/level', {
                levels,
            });
            // Append the new level to the levels list.
            $(CONFIG + ' .rg_level_list').empty().append(asListItems(level));
            // Update the hidden input with the new badges array.
            $('[name=config_levels]').val(JSON.stringify(levels));
        });
    });
};
