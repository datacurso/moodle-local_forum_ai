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
 * Local forum ai pending.
 *
 * @module      local_forum_ai/pending
 * @copyright   2025 Datacurso
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalFactory from 'core/modal_factory';
import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import { renderPost } from 'local_forum_ai/utils/render_post';
import { get_string as getString } from 'core/str';

/**
 * Initialize the pending AI responses interface.
 *
 * @returns {void}
 */
export const init = () => {
    // View details button.
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
                    aiResponseProposed,
                    saveLabel,
                    saveApproveLabel,
                    rejectLabel
                ] = await Promise.all([
                    getString('modal_title_pending', 'local_forum_ai'),
                    getString('discussion_label', 'local_forum_ai', data.discussion),
                    getString('no_posts', 'local_forum_ai'),
                    getString('ai_response_proposed', 'local_forum_ai'),
                    getString('save', 'local_forum_ai'),
                    getString('saveapprove', 'local_forum_ai'),
                    getString('reject', 'local_forum_ai'),
                ]);

                const body = await renderDiscussion(data, true, {
                    discussionLabel,
                    noPosts,
                    aiResponseProposed,
                    saveLabel,
                    saveApproveLabel,
                    rejectLabel
                });

                ModalFactory.create({
                    type: ModalFactory.types.DEFAULT,
                    title: modalTitle,
                    body: body,
                    large: true,
                }).done(modal => {
                    modal.show();
                    initAiEditHandlers(modal.getRoot(), data.token);
                });
            }).fail(Notification.exception);
        });
    });

    // Approve / Reject directly from the list.
    document.querySelectorAll('.action-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            const token = btn.dataset.token;
            const action = btn.dataset.action;

            Ajax.call([{
                methodname: 'local_forum_ai_approve_response',
                args: { token: token, action: action },
            }])[0].done(() => {
                const row = btn.closest('tr');
                if (row) {
                    row.remove();
                }
            })
                .fail(ex => {
                    const msg = ex.message;
                    Notification.addNotification({
                        message: msg,
                        type: 'error'
                    });
                });
        });
    });
};

/**
 * Render the pending discussion modal body using a Mustache template.
 *
 * @param {Object} data
 * @param {boolean} editMode
 * @param {Object} strings
 * @returns {Promise<string>}
 */
async function renderDiscussion(data, editMode = false, strings) {
    const posts = [];

    for (const post of data.posts) {
        const postHtml = await renderPost(post);
        posts.push({ html: postHtml });
    }

    return Templates.render('local_forum_ai/pending_modal', {
        course: data.course,
        forum: data.forum,
        discussionlabel: strings.discussionLabel,
        noposts: data.posts.length === 0,
        nopoststext: strings.noPosts,
        posts: posts,
        airesponseproposed: strings.aiResponseProposed,
        token: data.token,
        editmode: editMode,
        airesponse: data.airesponse,
        savelabel: strings.saveLabel,
        saveapprovelabel: strings.saveApproveLabel,
        rejectlabel: strings.rejectLabel,
    });
}

/**
 * Initialize handlers for saving and approving AI responses inside the modal.
 *
 * @param {object} root - The modal root element.
 * @param {string} token - The unique approval token.
 * @returns {void}
 */
function initAiEditHandlers(root, token) {
    root.on('click', '.save-ai', e => {
        e.preventDefault();
        const newMessage = root.find('#airesponse-edit').val();

        Ajax.call([{
            methodname: 'local_forum_ai_update_response',
            args: { token: token, message: newMessage },
        }])[0].done(response => {
            root.find('#airesponse-content').html(response.message);
            location.reload();
        }).fail(Notification.exception);
    });

    root.on('click', '.save-approve-ai', e => {
        e.preventDefault();
        const newMessage = root.find('#airesponse-edit').val();

        Ajax.call([{
            methodname: 'local_forum_ai_update_response',
            args: { token: token, message: newMessage },
        }])[0].done(() => {
            Ajax.call([{
                methodname: 'local_forum_ai_approve_response',
                args: { token: token, action: 'approve' },
            }])[0].done(() => {
                location.reload();
            }).fail(Notification.exception);
        }).fail(Notification.exception);
    });

    root.on('click', '.reject-ai', e => {
        e.preventDefault();

        Ajax.call([{
            methodname: 'local_forum_ai_approve_response',
            args: { token: token, action: 'reject' },
        }])[0].done(() => {
            location.reload();
        }).fail(Notification.exception);
    });
}
