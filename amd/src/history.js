// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Local forum ai history
 *
 * @module      local_forum_ai/history
 * @copyright   2025 Datacurso
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalFactory from 'core/modal_factory';
import Ajax from 'core/ajax';
import Notification from 'core/notification';
import { get_string as getString } from 'core/str';
import Templates from 'core/templates';
import { renderPost } from 'local_forum_ai/utils/render_post';

/**
 * Initializes the listeners to display AI response history.
 *
 * @returns {void}
 */
export const init = () => {
    document.querySelectorAll('.view-details').forEach(btn => {
        btn.addEventListener('click', e => {
            const token = e.currentTarget.dataset.token;

            Ajax.call([{
                methodname: 'local_forum_ai_get_details',
                args: { token: token },
            }])[0].done(async data => {
                const [
                    modalTitle,
                    discussionLabel,
                    noPosts,
                    aiResponse,
                    aiResponseApproved,
                    aiResponseRejected,
                    aiResponseExpired
                ] = await Promise.all([
                    getString('modal_title', 'local_forum_ai'),
                    getString('discussion_label', 'local_forum_ai', data.discussion),
                    getString('no_posts', 'local_forum_ai'),
                    getString('ai_response', 'local_forum_ai'),
                    getString('ai_response_approved', 'local_forum_ai'),
                    getString('ai_response_rejected', 'local_forum_ai'),
                    getString('ai_response_expired', 'local_forum_ai'),
                ]);

                const body = await renderDiscussion(data, {
                    discussionLabel,
                    noPosts,
                    aiResponse,
                    aiResponseApproved,
                    aiResponseRejected,
                    aiResponseExpired
                });

                ModalFactory.create({
                    type: ModalFactory.types.DEFAULT,
                    title: modalTitle,
                    body: body,
                    large: true,
                }).done(modal => modal.show());
            }).fail(Notification.exception);
        });
    });
};

/**
 * Renders the discussion modal body using a Mustache template.
 *
 * @param {Object} data
 * @param {Object} strings
 * @returns {Promise<string>}
 */
async function renderDiscussion(data, strings) {
    const posts = [];

    for (const post of data.posts) {
        const postHtml = await renderPost(post);
        posts.push({ html: postHtml });
    }

    let statusClass = 'bg-secondary';
    let statusIcon = 'fa fa-robot';
    let statusLabel = strings.aiResponse;

    if (data.status === 'approved') {
        statusClass = 'bg-success text-white';
        statusIcon = 'fa fa-check';
        statusLabel = strings.aiResponseApproved;
    } else if (data.status === 'rejected') {
        statusClass = 'bg-danger text-white';
        statusIcon = 'fa fa-times';
        statusLabel = strings.aiResponseRejected;
    } else if (data.status === 'expired') {
        statusClass = 'bg-warning text-dark';
        statusIcon = 'fa fa-hourglass-end';
        statusLabel = strings.aiResponseExpired;
    }

    return Templates.render('local_forum_ai/history_modal', {
        course: data.course,
        forum: data.forum,
        discussionlabel: strings.discussionLabel,
        noposts: data.posts.length === 0,
        nopoststext: strings.noPosts,
        posts: posts,
        statusclass: statusClass,
        statusicon: statusIcon,
        statuslabel: statusLabel,
        airesponse: data.airesponse,
    });
}
