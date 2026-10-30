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
 * The Rajab's Games content type of mod_interactivevideo: places the course block's badges on the video
 * timeline, lets learners claim them for XP, and edits their positions in the interaction form.
 *
 * Handlers on shared elements are namespaced (rajabsgames, rajabsgamesplay) or bound to the plugin's own
 * form container, so they neither remove nor catch other content types' handlers on the same ids.
 *
 * @module     block_rajabsgames/main
 * @copyright  2025 Sokunthearith Makara <sokunthearithmakara@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import $ from 'jquery';
import Base from 'mod_interactivevideo/type/base';
import DynamicForm from 'core_form/dynamicform';
import Template from 'core/templates';
import {renderAnnotationItems} from 'mod_interactivevideo/viewannotation';
import Ajax from 'core/ajax';
import {get_string as getString} from 'core/str';

export default class RajabsGames extends Base {
    /**
     * Sets up the badges of the video's games annotation for the learner.
     *
     * Outside the editor, and only when the video has a games annotation: fetches the course's badges
     * and the viewer's claims, shows the badge status bar when the annotation asks for it, and shows
     * each unclaimed badge for five seconds from its timestamp (timeupdate), pausing the video when
     * the annotation asks for it. Clicking a badge claims it, records the XP as the annotation's
     * completion and clears the block's cached content.
     *
     * @method init
     */
    async init() {
        let self = this;

        if (!this.isEditMode()) {
            const games = this.annotations.find(x => x.type == 'rajabsgames');
            if (!games) {
                return;
            }

            // One call returns the course's badges and the viewer's own claims; the server derives both
            // the course and the user, so nothing here names them.
            let state = {badges: '[]', blockid: 0, gameprogress: '[]'};
            try {
                state = await Ajax.call([{
                    methodname: 'block_rajabsgames_get_video_state',
                    args: {interactivevideoid: self.interaction, itemid: games.id},
                }])[0];
            } catch (err) {
                window.console.error(err);
            }

            let badges = games.content;
            badges = typeof badges === 'string' ? JSON.parse(badges || '[]') : (badges || []);

            let progress = JSON.parse(state.gameprogress || '[]');

            if (badges.length == 0) {
                return;
            }

            let badgeconfig = JSON.parse(state.badges || '[]');

            badges = badges.map(badge => {
                badge.isblock = true;
                badge.unused = false;
                let badgedata = badgeconfig.find(x => x.id == badge.badgeid);
                if (badgedata) {
                    badge.name = badgedata.name;
                    badge.picture = badgedata.picture;
                    badge.id = badgedata.id;
                } else {
                    badge.id = 0;
                }
                if (progress.find(x => x.name == badge.uniqueid)) {
                    badge.completed = true;
                }
                return badge;
            });

            // Remove the badges that are no longer available in the course.
            badges = badges.filter(x => x.id != 0);

            // Badges for status: unique badges based on id.
            if (games.intg2 == 1) {
                let badgesforstatus = [];
                badges.forEach(badge => {
                    badge = JSON.parse(JSON.stringify(badge));
                    if (!badgesforstatus.find(x => x.id == badge.id)) {
                        badgesforstatus.push(badge);
                    }
                });

                badgesforstatus = badgesforstatus.map(badge => {
                    badge.mine = progress.filter(x => x.id == badge.id).length;
                    badge.badgecount = badges.filter(x => x.id == badge.id).length;
                    badge.completed = badge.mine == badge.badgecount;
                    badge.classes = badge.mine < badge.badgecount || badge.badgecount == 0 ? 'filter' : '';
                    return badge;
                });

                let status = await Template.render('block_rajabsgames/status', {
                    badges: badgesforstatus,
                });

                $('.navbar .navigation').prepend(status);
                $('[data-region="chapterwrapper"] > div').append(status);
            }

            // Check progress for non-existing badges and remove them.
            progress = progress.filter(x => badges.find(y => y.uniqueid == x.name));

            let cummulatedXP = 0;
            progress.forEach(x => {
                cummulatedXP += Number(x.xp);
            });

            let incompletedBadges = badges.filter(x => !x.completed);

            if (incompletedBadges.length == 0) {
                return;
            }

            let badgeHTML = await Template.render('block_rajabsgames/badge', {
                badges: incompletedBadges,
            });

            // Put the html to each badge.
            let $badgeHTML = $('<div>' + badgeHTML + '</div>');
            const xpString = await getString('xp', 'block_rajabsgames');
            incompletedBadges = incompletedBadges.map((x) => {
                let $el = $badgeHTML.children('.rg_badge_item').filter(function() {
                    return this.getAttribute('data-id') === String(x.id);
                }).first();
                // The popup is no list: drop the item's listitem role.
                $el = $el.clone().removeAttr('role');
                $el.addClass('pulse-sm jellyIn');
                $el.attr('data-name', x.uniqueid);
                $el.attr('data-xp', x.xp);
                if (x.xp > 0) {
                    $el.append(self.buildXpLabel(x.xp, xpString));
                }
                x.html = $el.prop('outerHTML');
                x.classes = self.getPosition(x.position);
                return x;
            });

            $(document).off('timeupdate.rajabsgamesplay').on('timeupdate.rajabsgamesplay', async function(e) {
                const t = Number(e.originalEvent.detail.time);
                const badge = incompletedBadges.find(x => x.timestamp <= t && x.timestamp + 5 >= t && !x.completed);
                let $videoBlock = $('.video-block');
                if ($videoBlock.length === 0 && $('#video-wrapper').length > 0) {
                    $videoBlock = $('<div class="video-block" aria-hidden="true"></div>').appendTo('#video-wrapper');
                }
                let isEmpty = $videoBlock.find('.rg_badge_item').length == 0;
                if (!badge && !isEmpty) {
                    $videoBlock.empty();
                    // Remove all classes.
                    $videoBlock.attr('class', 'video-block');
                    return;
                }
                if ($videoBlock.find('.rg_badge_item').length > 0) {
                    return;
                }
                // If there is a matching badge on timestamp, show it.
                if (badge) {
                    $videoBlock.empty();
                    // Remove all classes.
                    $videoBlock.attr('class', 'video-block');
                    $videoBlock.addClass(badge.classes + ' p-2');
                    $videoBlock.append(badge.html);
                    if (games.intg3 == 1) {
                        // Pause the video.
                        self.player.pause();
                    }
                }
            });

            // Claim the badge.
            const claimSelector = '.video-block .rg_badge_item';
            $(document).off('click.rajabsgamesplay', claimSelector).on('click.rajabsgamesplay', claimSelector, async function(e) {
                e.preventDefault();
                e.stopImmediatePropagation();

                // Update the status bar.
                if (games.intg2 == 1) {
                    const claimedid = $(this).attr('data-id');
                    let bdg = $('.block-rajabsgames-status .rg_badgestatus_item').filter(function() {
                        return this.getAttribute('data-id') === claimedid;
                    });
                    if (bdg.length > 0) {
                        let count = bdg.find('.badge-count');
                        count.attr('data-count', Number(count.attr('data-count')) + 1);
                        if (count.attr('data-count') == count.attr('data-total')) {
                            bdg.find('img').removeClass('filter');
                        }
                        bdg.find('img').addClass('jellyIn');
                        setTimeout(() => {
                            bdg.find('img').removeClass('jellyIn');
                        }, 300);
                        count.text(count.attr('data-count'));
                    }
                }

                // Fire the confetti.
                if (typeof window.fireConfetti === 'function') {
                    window.fireConfetti();
                }

                $(this).removeClass('jellyIn').addClass('jellyIn').fadeOut(300, () => {
                    $(this).remove();
                });

                // Update the progress to database.
                let details = {};
                const completeTime = new Date();
                let windowAnno = (window.ANNOS || []).find(x => x.id == games.id) ||
                    {duration: 0, newstarttime: completeTime.getTime()};
                details.xp = cummulatedXP + Number($(this).data('xp'));
                cummulatedXP = details.xp;
                const startTime = windowAnno.newstarttime || completeTime.getTime();
                details.duration = (windowAnno.duration || 0) + (completeTime.getTime() - startTime);
                details.timecompleted = completeTime.getTime();
                details.hasDetails = false;
                progress.push({
                    id: $(this).data('id'),
                    xp: $(this).data('xp'),
                    name: $(this).data('name'),
                });

                details.gameprogress = progress;
                details.completed = details.xp == games.xp;
                self.toggleCompletion(games.id, 'automatic', details);
                self.addNotification(await getString('xpearned', 'mod_interactivevideo', Number($(this).data('xp'))), 'success');

                // Remove the badge from the list.
                incompletedBadges = incompletedBadges.filter(x => x.uniqueid != $(this).data('name'));
                if (incompletedBadges.length == 0) {
                    // Stop showing badges.
                    $(document).off('timeupdate.rajabsgamesplay');
                }

            });

        }
    }

    /**
     * Toggle completion of an item
     * @param {number} id The annotation id
     * @param {string} type The type of completion (manual, automatic)
     * @param {{}} [details={}] Completion details
     * @returns {Promise}
     */
    async toggleCompletion(id, type = 'automatic', details = {}) {
        let self = this;
        // Skip if the page is the interactions page or in preview-mode.
        if (self.isEditMode()) {
            return Promise.resolve(); // Return a resolved promise for consistency
        }
        if (self.isPreviewMode()) {
            self.addNotification(await getString('completionnotrecordedinpreviewmode', 'mod_interactivevideo'));
            return Promise.resolve(); // Return a resolved promise for consistency
        }

        // Get existing progress so far.
        const gradableitems = self.annotations.filter(x => x.hascompletion == '1');
        const totalXp = gradableitems.map(({xp}) => Number(xp)).reduce((a, b) => a + b, 0);
        let completedItems = gradableitems.filter(({completed}) => completed);
        let earnedXp = completedItems.map(({earned}) => Number(earned)).reduce((a, b) => a + b, 0);

        completedItems = completedItems.map(({id}) => id);

        // Prepare details.
        let thisItem = gradableitems.find(({id: itemId}) => itemId == id);
        let completionDetails = {
            id,
        };
        const completeTime = new Date();
        completionDetails.hasDetails = details.details ? true : false;
        if (details.hasDetails) {
            completionDetails.hasDetails = true;
        }
        completionDetails.xp = details.xp || thisItem.xp;
        completionDetails.timecompleted = details.timecompleted || completeTime.getTime();
        const completiontime = completeTime.toLocaleString();
        completionDetails.gameprogress = details.gameprogress || [];
        completionDetails.reportView = details.reportView ||
            `<span data-bs-toggle="tooltip" data-bs-html="true"
                 title='<span class="d-flex flex-column align-items-start"><span><i class="bi bi-calendar iv-mr-2"></i>
                 ${completiontime}</span></span>'>
                 <i class="fa fa-${details.completed ? 'check text-success' : 'circle-o text-secondary'} "></i><br>
                 <span>${Number(completionDetails.xp)}</span></span>`;
        if (details.completed) {
            completedItems.push(id.toString());
        }
        if (thisItem.earned > 0) { // In case of resubmission.
            // Remove the earned XP from the total XP.
            earnedXp -= Number(thisItem.earned);
        }
        earnedXp += Number(completionDetails.xp);

        // Make sure the completed items are unique.
        completedItems = [...new Set(completedItems)];

        let completed;
        if (Number(self.completionpercentage) > 0) { // Completion percentage is set.
            completed = (completedItems.length / gradableitems.length) * 100 >= Number(self.completionpercentage) ? 1 : 0;
        } else {
            completed = gradableitems.length == completedItems.length ? 1 : 0;
        }
        return new Promise((resolve) => {
            $.ajax({
                url: `${M.cfg.wwwroot}/mod/interactivevideo/ajax.php`,
                method: "POST",
                dataType: "text",
                data: {
                    action: 'save_progress',
                    markdone: 'mark-done',
                    sesskey: M.cfg.sesskey,
                    id: self.interaction,
                    uid: self.userid,
                    percentage: (completedItems.length / gradableitems.length) * 100,
                    g: parseFloat((earnedXp / totalXp) * self.grademax).toFixed(2),
                    gradeiteminstance: self.gradeiteminstance,
                    c: completed,
                    xp: earnedXp,
                    completeditems: JSON.stringify(completedItems),
                    completiondetails: JSON.stringify(completionDetails),
                    details: JSON.stringify(details.details || {}),
                    annotationtype: thisItem.type,
                    token: self.token,
                    cmid: self.cm,
                    completionid: self.completionid,
                    contextid: thisItem.contextid,
                    updatestate: self.completionpercentage > 0 || Object.keys(self.extracompletion).length != 0 ? 1 : 0,
                    courseid: self.course,
                },
                success: (res) => {
                    // Update the annotations array.
                    const annotations = self.annotations.map(x => {
                        if (x.id == id) {
                            x.completed = details.completed ? true : 'pending';
                            x.earned = completionDetails.xp || 0;
                        }
                        return x;
                    });

                    renderAnnotationItems(annotations, self.start, self.end - self.start);
                    thisItem.earned = completionDetails.xp || 0;
                    // Play a popup sound.
                    let audio = new Audio(M.cfg.wwwroot + '/mod/interactivevideo/sounds/point-awarded.mp3');
                    audio.play();
                    self.dispatchEvent('completionupdated', {
                        annotations,
                        completionpercentage: (completedItems.length / gradableitems.length) * 100,
                        grade: parseFloat((earnedXp / totalXp) * self.grademax).toFixed(2),
                        completed,
                        xp: earnedXp,
                        completeditems: completedItems,
                        target: thisItem,
                        action: 'mark-done',
                        type,
                        response: res,
                    });
                    resolve();
                }
            });
        });
    }

    /**
     * Builds the "N XP" label a badge shows under its picture, as nodes: the XP is teacher-set data.
     *
     * @param {Number|String} xp The XP the badge awards
     * @param {String} xpString The XP unit label
     * @returns {jQuery} The label
     */
    buildXpLabel(xp, xpString) {
        return $('<small class="text-muted"></small>')
            .text(xp + ' ')
            .append($('<sup></sup>').text(xpString));
    }

    /**
     * Creates the interaction form's container in the editor's annotation panel.
     *
     * It carries the plugin's scoping class, and the form's buttons are bound on it rather than on the
     * document: its handlers go away with it, and they run before (and stop) any handler another content
     * type left on the document for the same button ids.
     *
     * @returns {jQuery} The container
     */
    createFormRoot() {
        $('#annotationwrapper table').hide();
        return $('<div id="form" class="block-rajabsgames block-rajabsgames-interaction w-100 p-3"></div>')
            .appendTo('#annotationwrapper');
    }

    /**
     * Removes the editor's badge preview and stops removing it on every timeupdate.
     *
     * @returns {void}
     */
    clearPreview() {
        $('#annotation-canvas .block-rajabsgames-preview').remove();
        $(document).off('timeupdate.rajabsgames');
    }

    /**
     * Closes the interaction form and gives the annotation list back.
     *
     * @returns {void}
     */
    closeForm() {
        this.clearPreview();
        $('#annotationwrapper #form').remove();
        $('#annotationwrapper table').show();
        $('#addcontent, #importcontent').removeClass('no-pointer-events');
    }

    /**
     * Binds the interaction form's cancel and submit buttons and its validation error.
     *
     * @param {jQuery} $formroot The form's container
     * @param {DynamicForm} gamesform The form
     */
    bindFormButtons($formroot, gamesform) {
        let self = this;

        $formroot.off('click.rajabsgames', '#cancel-submit').on('click.rajabsgames', '#cancel-submit', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            self.closeForm();
        });

        $formroot.off('click.rajabsgames', '#submitform-submit').on('click.rajabsgames', '#submitform-submit', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            // A validation error reloads the form, and onEditFormLoaded() binds the preview again.
            self.clearPreview();
            const event = gamesform.trigger(gamesform.events.SUBMIT_BUTTON_PRESSED);
            if (!event.defaultPrevented) {
                gamesform.submitFormAjax();
            }
        });

        gamesform.addEventListener(gamesform.events.SERVER_VALIDATION_ERROR, (e) => {
            e.stopImmediatePropagation();
            self.onEditFormLoaded(gamesform);
            self.validateTimestampFieldValue('timestampassist', 'timestamp');
        });
    }

    /**
     * Add an annotation
     * @param {Array} annotations The annotations array
     * @param {number} timestamp The timestamp
     * @param {number} coursemodule The course module id
     * @returns {void}
     */
    addAnnotation(annotations, timestamp, coursemodule) {
        $('#addcontent, #importcontent').addClass('no-pointer-events');
        let self = this;
        this.annotations = annotations;

        const startHMS = self.convertSecondsToHMS(self.start);
        const endHMS = self.convertSecondsToHMS(self.end);
        const timestampHMS = timestamp > 0 ? self.convertSecondsToHMS(timestamp) : startHMS;

        const data = {
            id: 0,
            timestamp: -1,
            timestampassist: timestampHMS,
            title: self.prop.title,
            start: startHMS,
            end: endHMS,
            contextid: M.cfg.contextid,
            type: self.prop.name,
            courseid: self.course,
            cmid: coursemodule,
            annotationid: self.interaction,
            hascompletion: self.prop.hascompletion ? 1 : 0,
        };

        const $formroot = self.createFormRoot();
        $("#contentmodal").modal('hide');
        $('#addcontentdropdown a').removeClass('active');

        const gamesform = new DynamicForm($formroot[0], self.prop.form);
        gamesform.load(data);

        self.onEditFormLoaded(gamesform);
        self.validateTimestampFieldValue('timestampassist', 'timestamp');

        self.bindFormButtons($formroot, gamesform);

        gamesform.addEventListener(gamesform.events.FORM_SUBMITTED, async(e) => {
            e.preventDefault();
            e.stopImmediatePropagation();
            $.ajax({
                url: M.cfg.wwwroot + '/mod/interactivevideo/ajax.php',
                method: "POST",
                dataType: "text",
                data: {
                    action: 'get_item',
                    id: e.detail.id,
                    sesskey: M.cfg.sesskey,
                    contextid: M.cfg.courseContextId,
                    token: self.token,
                    cmid: self.cm,
                },
                success: function(data) {
                    const newAnnotation = JSON.parse(data);
                    self.dispatchEvent('annotationupdated', {
                        annotation: newAnnotation,
                        action: 'add'
                    });
                }
            });
            self.closeForm();
        });
    }

    /**
     * Edit an annotation
     * @param {Array} annotations The annotations array
     * @param {number} id The annotation id
     * @returns {void}
     */
    editAnnotation(annotations, id) {
        // Disable pointer events on some DOMs.
        $('#addcontent, #importcontent').addClass('no-pointer-events');
        this.annotations = annotations;
        let self = this;
        const annotation = annotations.find(x => x.id == id);
        const timestamp = annotation.timestamp;
        const timestampassist = this.convertSecondsToHMS(timestamp);

        annotation.timestampassist = timestampassist;
        annotation.start = this.convertSecondsToHMS(this.start);
        annotation.end = this.convertSecondsToHMS(this.end);
        annotation.contextid = M.cfg.contextid;

        const $formroot = self.createFormRoot();
        const gamesform = new DynamicForm($formroot[0], self.prop.form);
        gamesform.load(annotation);

        self.onEditFormLoaded(gamesform);
        self.validateTimestampFieldValue('timestampassist', 'timestamp');

        self.bindFormButtons($formroot, gamesform);

        gamesform.addEventListener(gamesform.events.FORM_SUBMITTED, async(e) => {
            e.preventDefault();
            e.stopImmediatePropagation();
            self.annotations = self.annotations.filter(x => x.id != id);
            $.ajax({
                url: M.cfg.wwwroot + '/mod/interactivevideo/ajax.php',
                method: "POST",
                dataType: "text",
                data: {
                    action: 'get_item',
                    id: e.detail.id,
                    sesskey: M.cfg.sesskey,
                    contextid: M.cfg.courseContextId,
                    token: self.token,
                    cmid: self.cm,
                },
            }).done(function(data) {
                const updated = JSON.parse(data);
                self.dispatchEvent('annotationupdated', {
                    annotation: updated,
                    action: 'edit'
                });
            });
            self.closeForm();
        });
    }

    /**
     * Get the flex alignment classes that place a badge at a position of the video.
     * @param {String} position The position, one of the form's position names ('top-left' ... 'bottom-right')
     * @returns {String} The classes
     */
    getPosition(position = 'center-center') {
        let classes = '';
        switch (position) {
            case 'top-left':
                classes = 'align-items-start justify-content-start';
                break;
            case 'top-center':
                classes = 'align-items-start justify-content-center';
                break;
            case 'top-right':
                classes = 'align-items-start justify-content-end';
                break;
            case 'center-left':
                classes = 'align-items-center justify-content-start';
                break;
            case 'center-center':
                classes = 'align-items-center justify-content-center';
                break;
            case 'center-right':
                classes = 'align-items-center justify-content-end';
                break;
            case 'bottom-left':
                classes = 'align-items-end justify-content-start';
                break;
            case 'bottom-center':
                classes = 'align-items-end justify-content-center';
                break;
            case 'bottom-right':
                classes = 'align-items-end justify-content-end';
                break;
            default:
                break;
        }
        return classes;
    }

    /**
     * Builds one row of the badge positions list: unique name, badge, position, XP, timestamp and buttons.
     *
     * Built as nodes: the unique name, the badge names and the XP are teacher-set data. The selected
     * options carry the selected attribute, which clone() copies when a row is duplicated.
     *
     * @param {Array} badgeoptions The course's badges: id, name
     * @param {Array} positions The positions: name, label
     * @param {Object} dest The row's values: uniqueid, badgeid, position, xp, timestamp
     * @param {Boolean} deletable Whether the row's delete button is enabled
     * @returns {jQuery} The row
     */
    buildDestinationRow(badgeoptions, positions, dest, deletable) {
        const str = (id) => M.util.get_string(id, 'block_rajabsgames');
        const $row = $('<div class="input-group mb-1"></div>');

        $('<input type="text" class="uniqueid form-control">')
            .val(dest.uniqueid || '')
            .attr('placeholder', str('uniquename'))
            .appendTo($row);

        const $badges = $('<select class="form-select badgeoption"></select>').appendTo($row);
        $('<option value=""></option>').text(str('selectabadge')).appendTo($badges);
        badgeoptions.forEach((badge) => {
            const $option = $('<option></option>').val(badge.id).text(badge.name).appendTo($badges);
            if (badge.id == dest.badgeid) {
                $option.attr('selected', 'selected');
            }
        });

        const $positions = $('<select class="form-select positionoption"></select>').appendTo($row);
        positions.forEach((position) => {
            const $option = $('<option></option>').val(position.name).text(position.label).appendTo($positions);
            if (position.name == (dest.position || 'center-center')) {
                $option.attr('selected', 'selected');
            }
        });

        $('<input type="text" class="form-control xp">')
            .val(dest.xp || 0)
            .attr('placeholder', str('xp'))
            .appendTo($row);

        $('<input type="text" placeholder="00:00:00" class="form-control timestamp-input">')
            .val(this.convertSecondsToHMS(dest.timestamp))
            .attr('title', str('rightclicktousecurrenttime'))
            .appendTo($row);

        $row.append(
            '<button class="btn goto-dest btn-secondary px-2" type="button"><i class="bi bi-play-fill fs-25px"></i></button>',
            '<button class="btn add-dest btn-secondary" type="button"><i class="bi bi-plus-lg fs-unset"></i></button>',
            '<button class="btn btn-danger delete-dest" type="button"><i class="bi bi-trash3-fill fs-unset"></i></button>'
        );
        if (!deletable) {
            $row.find('.delete-dest').addClass('disabled').attr('disabled', 'disabled');
        }
        return $row;
    }

    /**
     * Handles the loading of the edit form and initializes the destination list.
     *
     * Called again after a server validation error reloads the form, so every handler it binds is
     * unbound first.
     *
     * @param {HTMLElement} form - The form element that is being edited.
     * @param {Event} event - The event that triggered the form load.
     * @returns {Object} An object containing the form and event.
     */
    onEditFormLoaded(form, event) {
        let self = this;
        let body = $('#annotationwrapper #form');
        let positions = [
            {
                'name': 'top-left',
                'label': M.util.get_string('topleft', 'block_rajabsgames'),
            },
            {
                'name': 'top-center',
                'label': M.util.get_string('topcenter', 'block_rajabsgames'),
            },
            {
                'name': 'top-right',
                'label': M.util.get_string('topright', 'block_rajabsgames'),
            },
            {
                'name': 'center-left',
                'label': M.util.get_string('centerleft', 'block_rajabsgames'),
            },
            {
                'name': 'center-center',
                'label': M.util.get_string('centercenter', 'block_rajabsgames'),
            },
            {
                'name': 'center-right',
                'label': M.util.get_string('centerright', 'block_rajabsgames'),
            },
            {
                'name': 'bottom-left',
                'label': M.util.get_string('bottomleft', 'block_rajabsgames'),
            },
            {
                'name': 'bottom-center',
                'label': M.util.get_string('bottomcenter', 'block_rajabsgames'),
            },
            {
                'name': 'bottom-right',
                'label': M.util.get_string('bottomright', 'block_rajabsgames'),
            }
        ];
        const checkContentField = () => {
            if (body.find('[name=content]').length > 0) {
                let dest = body.find('[name=content]').val();
                let badgeoptions = body.find('[name=badges]').val();
                if (badgeoptions) {
                    badgeoptions = JSON.parse(badgeoptions);
                } else {
                    badgeoptions = [];
                }
                const $list = body.find('#destination-list');
                if (dest == '' || JSON.parse(dest).length == 0) {
                    // A hidden template row: the add button clones it.
                    const $template = self.buildDestinationRow(badgeoptions, positions, {timestamp: self.start}, false);
                    $template.addClass('d-none');
                    $list.append($template);
                } else {
                    dest = JSON.parse(dest);
                    dest.forEach((d, i) => {
                        $list.append(self.buildDestinationRow(badgeoptions, positions, d, i != 0));
                    });
                    body.find('.input-group [type="text"]').trigger('input');
                }
            } else {
                requestAnimationFrame(checkContentField);
            }
        };

        requestAnimationFrame(checkContentField);

        // Bound on the form's container under the plugin's namespace, unbound first: this runs again after a
        // validation error.
        const bind = (event, selector, handler) => {
            body.off(event + '.rajabsgames', selector).on(event + '.rajabsgames', selector, handler);
        };

        bind('click', '#add-destination', function() {
            const $last = body.find('#destination-list .input-group').last();
            $last.find('.add-dest').trigger('click');
        });

        bind('click', '.input-group .add-dest', async function(e) {
            e.stopImmediatePropagation();
            let $thisrow = $(this);
            let $parent = $thisrow.closest('.input-group');
            let $row = $parent.clone();
            $row.removeClass('d-none');
            let currentTime = await self.player.getCurrentTime();
            $row.find('input.timestamp-input').val(self.convertSecondsToHMS(currentTime));
            $row.find('.uniqueid').val('');
            $row.find('.xp').val(0);
            $row.find('.delete-dest').removeClass('disabled').removeAttr('disabled');
            $parent.after($row);
            $parent.find('[type="text"]').trigger('input');
        });

        bind('click', '.input-group .delete-dest', function(e) {
            e.stopImmediatePropagation();
            $(this).closest('.input-group').remove();
            body.find('.input-group [type="text"]').trigger('input');
        });

        bind('click', '.input-group .goto-dest', async function(e) {
            e.stopImmediatePropagation();
            $('#annotation-canvas .block-rajabsgames-preview').remove();
            const $row = $(this).closest('.input-group');
            const timestamp = $row.find('input.timestamp-input').val();
            const seconds = self.convertHMSToSeconds(timestamp);
            const position = $row.find('.positionoption').val();
            const badgeid = $row.find('.badgeoption').val();
            const $badge = body.find('.rg_badge_item').filter(function() {
                return this.getAttribute('data-id') === badgeid;
            }).first().clone().removeAttr('role');
            const xp = $row.find('.xp').val();
            if (xp > 0) {
                $badge.append(self.buildXpLabel(xp, M.util.get_string('xp', 'block_rajabsgames')));
            }
            let classes = self.getPosition(position);
            const currentTime = await self.player.getCurrentTime();
            if (currentTime != seconds) {
                await self.player.seek(seconds);
            }
            $('<div id="badge-preview" class="block-rajabsgames block-rajabsgames-preview"></div>')
                .addClass('d-flex h-100 w-100 p-2 z-index-1 ' + classes)
                .append($badge)
                .appendTo('#annotation-canvas');
        });

        bind('contextmenu', '.input-group .timestamp-input', async function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            // Get the current time of the video.
            let currentTime = await self.player.getCurrentTime();
            $(this).val(self.convertSecondsToHMS(currentTime));
            $(this).trigger('input');
        });

        bind('input', '.input-group :input', function() {
            let dest = [];
            body.find('#destination-list .input-group').each(function() {
                const badgeid = $(this).find('.badgeoption').val();
                const xp = $(this).find('.xp').val() || 0;
                const position = $(this).find('.positionoption').val();
                const $timestamp = $(this).find('.timestamp-input');
                const uniqueid = $(this).find('.uniqueid').val();
                if (uniqueid != "" && badgeid != '' && $timestamp.val() && !$timestamp.hasClass('is-invalid')) {
                    const seconds = self.convertHMSToSeconds($timestamp.val());
                    dest.push({
                        badgeid,
                        xp,
                        position,
                        timestamp: seconds,
                        uniqueid
                    });
                }
            });
            dest.sort((a, b) => a.timestamp - b.timestamp);
            // Keep one position per timestamp and one per unique name: the first in timestamp order wins.
            const timestamps = new Set();
            const uniqueids = new Set();
            dest = dest.filter((d) => {
                if (timestamps.has(d.timestamp) || uniqueids.has(d.uniqueid)) {
                    return false;
                }
                timestamps.add(d.timestamp);
                uniqueids.add(d.uniqueid);
                return true;
            });
            // Total XP.
            let totalxp = 0;
            dest.forEach((d) => {
                totalxp += Number(d.xp);
            });
            if (dest.length == 0) {
                body.find('form [name=content]').val('');
                body.find('form [name=xp]').val(0);
            } else {
                body.find('form [name=content]').val(JSON.stringify(dest));
                body.find('form [name=xp]').val(totalxp);
            }
        });

        // The preview stands for the badge at one time only: any playback removes it.
        $(document).off('timeupdate.rajabsgames').on('timeupdate.rajabsgames', function() {
            $('#annotation-canvas .block-rajabsgames-preview').remove();
        });

        return {form, event};
    }

    /**
     * Runs the annotation when IV launches it, which for this type shows nothing.
     *
     * The games annotation has no timestamp ('hastimestamp' => false): init() shows each badge from
     * timeupdate, so the player never launches it. The editor still does when its row's title is clicked
     * (mod_interactivevideo editannotation.js, the 'tr.annotation .title' handler), and the base class
     * would then render an empty viewer; the badges are previewed from the form's play buttons instead.
     *
     * @returns {Promise<void>}
     */
    async runInteraction() {
        // Nothing to render: see the docblock.
    }

    /**
     * Called by IV after the annotation is added or edited; the base class runs the interaction, which
     * this type does not have (see runInteraction()).
     *
     * @returns {void}
     */
    postEditCallback() {
        // Nothing to run.
    }

    /**
     * Renders the annotation's row in the editor's list, without inline XP editing: the XP is the sum of
     * the badges' XP, which only the form computes.
     *
     * @param {Array} annotations The annotations array
     * @param {jQuery} listItem The row
     * @param {Object} item The annotation
     * @returns {jQuery} The row
     */
    renderEditItem(annotations, listItem, item) {
        listItem = super.renderEditItem(annotations, listItem, item);
        listItem.find('[data-editable="xp"]').removeAttr('data-editable');
        return listItem;
    }
}
