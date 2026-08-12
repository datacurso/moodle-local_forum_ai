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
 * Local forum ai review.
 *
 * @module      local_forum_ai/review
 * @copyright   2025 Datacurso
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import { get_string as getString } from 'core/str';

/**
 * Initialize the AI response review interface.
 *
 * @returns {Promise<void>} Resolves when all event handlers are registered.
 */
export const init = async () => {
    const editBtn = document.getElementById("edit-btn");
    const viewDiv = document.getElementById("airesponse-view");
    const editForm = document.getElementById("airesponse-edit");
    const cancelBtn = document.getElementById("cancel-edit");
    const textarea = editForm ? editForm.querySelector("textarea[name='message']") : null;
    const saveBtn = editForm ? editForm.querySelector("button[type='submit']") : null;
    const token = editForm ? editForm.dataset.token : null;

    // Preload localized strings.
    const [
        strUpdatedSuccess,
        strUpdatedError,
        strApproved,
        strRejected,
        strActionFailed
    ] = await Promise.all([
        getString('response_updated', 'local_forum_ai'),
        getString('response_update_failed', 'local_forum_ai'),
        getString('response_approved', 'local_forum_ai'),
        getString('response_rejected', 'local_forum_ai'),
        getString('action_failed', 'local_forum_ai'),
    ]);

    // Toggle edit mode.
    if (editBtn) {
        editBtn.addEventListener("click", () => {
            viewDiv.style.display = "none";
            editForm.style.display = "block";
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener("click", () => {
            editForm.style.display = "none";
            viewDiv.style.display = "block";
        });
    }

    // Save changes via AJAX.
    if (saveBtn) {
        saveBtn.addEventListener("click", e => {
            e.preventDefault();
            const newMessage = textarea.value;

            Ajax.call([{
                methodname: "local_forum_ai_update_response",
                args: { token: token, message: newMessage },
            }])[0].done(response => {
                if (response.status === "ok") {
                    // Contract: response.message is server-side purified/formatted HTML
                    // (clean_text/format_text with HTMLPurifier), so direct HTML injection
                    // is the reviewed transformation here.
                    viewDiv.querySelector(".card-text").innerHTML = response.message;

                    Notification.addNotification({
                        message: strUpdatedSuccess,
                        type: "success"
                    });

                    editForm.style.display = "none";
                    viewDiv.style.display = "block";
                } else {
                    Notification.addNotification({
                        message: strUpdatedError,
                        type: "error"
                    });
                }
            }).fail(Notification.exception);
        });
    }

    // Approve or reject responses.
    document.querySelectorAll(".action-btn").forEach(btn => {
        btn.addEventListener("click", e => {
            e.preventDefault();

            const action = btn.dataset.action;
            const token = btn.dataset.token;

            Ajax.call([{
                methodname: "local_forum_ai_approve_response",
                args: { token: token, action: action },
            }])[0].done(response => {
                if (response.success) {
                    Notification.addNotification({
                        message: action === "approve" ? strApproved : strRejected,
                        type: "success"
                    });

                    setTimeout(() => {
                        window.location.href =
                            `${M.cfg.wwwroot}/mod/forum/discuss.php?d=${btn.dataset.discussionid}`;
                    }, 1500);
                } else {
                    Notification.addNotification({
                        message: strActionFailed,
                        type: "error"
                    });
                }
            }).fail(Notification.exception);
        });
    });
};
