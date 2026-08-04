// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Forum AI grading integration module.
 *
 * Responsible for injecting and managing the "Review with AI" button
 * inside the Moodle forum grading interface. The button is dynamically
 * repositioned depending on the active grading method (simple grade,
 * rubric, or marking guide) and reacts to real DOM changes instead
 *
 * This implementation improves performance and stability by using:
 *  - Moodle PubSub events
 *  - MutationObserver for DOM changes
 *  - Controlled button state handling
 *
 * @module      local_forum_ai/analyze
 * @copyright   2025 Datacurso
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/pubsub', 'core/ajax', 'core/str', 'core/templates'],
    function ($, PubSub, Ajax, Str, Templates) {

        /**
         * Initializes the AI button integration for forum grading.
         *
         * @returns {void}
         */
        function init() {

            const $button = $('#forum-ai-review-btn');
            const $messagesContainer = $('#forum-ai-review-messages');
            let lastState = null;

            if ($button.length === 0) {
                return;
            }

            /**
             * Shows a notification message above the button.
             *
             * @param {string} message - The message to display
             * @param {string} type - Type of notification: 'success', 'error', 'warning', 'info'
             * @returns {Promise}
             */
            const showNotification = function (message, type = 'info') {

                // Clear previous notifications
                $messagesContainer.empty();

                // Map types to Moodle Bootstrap classes
                const alertTypes = {
                    'success': 'success',
                    'error': 'danger',
                    'warning': 'warning',
                    'info': 'info'
                };

                const iconClasses = {
                    'success': 'fa-check-circle',
                    'error': 'fa-exclamation-circle',
                    'warning': 'fa-exclamation-triangle',
                    'info': 'fa-info-circle'
                };

                const alertType = alertTypes[type] || alertTypes.info;
                const iconClass = iconClasses[type] || iconClasses.info;

                // Render the template
                return Templates.render('local_forum_ai/notification_message', {
                    message: message,
                    type: alertType,
                    icon: iconClass
                }).then(function (html) {
                    $messagesContainer.html(html).show();

                    // Auto-hide after 10 seconds (except errors)
                    if (type !== 'error') {
                        setTimeout(function () {
                            $messagesContainer.find('.alert').fadeOut(function () {
                                $(this).remove();
                            });
                        }, 10000);
                    }

                    return true;
                }).catch(function (error) {
                    // Fallback if template rendering fails
                    window.console.error('Error rendering notification template:', error);
                    return false;
                });
            };

            /**
             * Injects the AI button into the active grading container.
             * It detects which grading form is currently visible and
             * safely places the button inside it.
             *
             * @returns {void}
             */
            const injectButtonIntoGrader = function () {

                const simpleInput = document.querySelector('input[name="grade"]');
                const rubricForm = document.querySelector('form[id^="gradingform_rubric"]');
                const guideForm = document.querySelector('form[id^="gradingform_guide"]');

                let target = null;

                if (simpleInput) {
                    target = simpleInput.closest('form');
                } else if (rubricForm) {
                    target = rubricForm;
                } else if (guideForm) {
                    target = guideForm;
                }

                if (!target || !$(target).is(':visible')) {
                    if (lastState !== 'hidden') {
                        $button.hide();
                        $messagesContainer.hide();
                        lastState = 'hidden';
                    }
                    return;
                }

                // Get the complete wrapper (button + messages)
                const $wrapper = $button.closest('.fitem');

                if (!$wrapper.parent().is(target)) {
                    $wrapper.detach().prependTo(target).show();
                    $button.show();
                    lastState = 'visible';
                } else if (!$button.is(':visible')) {
                    $button.show();
                    lastState = 'visible';
                }
            };

            /**
             * Reinject button when the Moodle grading drawer opens.
             */
            PubSub.subscribe('drawer-opened', function () {
                setTimeout(injectButtonIntoGrader, 300);
            });

            observeUserChange();
            observeGradingPanel();

            // Initial injection attempt on load
            setTimeout(injectButtonIntoGrader, 500);

            /**
             * Handles AI button click event.
             *
             * @param {Event} e Click event.
             */
            $(document).on('click', '#forum-ai-review-btn', async function (e) {
                e.preventDefault();

                const button = this;

                if (button.classList.contains('forum-ai-btnloading')) {
                    return;
                }

                // Clear previous notifications
                $messagesContainer.empty().hide();

                await setLoading(button);

                const cmid = new URLSearchParams(window.location.search).get('id');
                const userNode = document.querySelector('[data-region="name"][data-userid]');
                const userid = userNode ? userNode.getAttribute('data-userid') : null;

                if (!cmid || !userid) {
                    resetLoading(button);

                    showNotification('Missing required parameters (cmid or userid)', 'error');

                    return;
                }

                Ajax.call([{
                    methodname: 'local_forum_ai_process_review',
                    args: {
                        cmid: parseInt(cmid, 10),
                        userid: parseInt(userid, 10)
                    }
                }])[0].done(function (response) {

                    try {
                        const data = JSON.parse(response.data);

                        if (response.type === 'simple') {
                            applySimpleGrade(data);
                        }

                        if (response.type === 'rubric') {
                            applyRubricGrade(data);
                        }

                        if (response.type === 'guide') {
                            applyGuideGrade(data);
                        }

                        // Show success message
                        Str.get_string('gradesappliedsuccessfully', 'local_forum_ai').done(function (message) {
                            showNotification(message, 'success');
                        });

                        resetLoading(button);

                    } catch (error) {
                        resetLoading(button);

                        showNotification('Error processing response: ' + error.message, 'error');
                    }

                }).fail(function (error) {
                    resetLoading(button);

                    // Extract detailed error message
                    let errorMessage = '';

                    if (error.error) {
                        errorMessage = error.error;
                    } else if (error.message) {
                        errorMessage = error.message;
                    } else if (error.exception && error.exception.message) {
                        errorMessage = error.exception.message;
                    } else if (error.debuginfo) {
                        errorMessage = error.debuginfo;
                    } else {
                        errorMessage = JSON.stringify(error);
                    }

                    showNotification(errorMessage, 'error');
                });
            });

            /**
             * Observes changes in the user selector to reinject the button
             * when a different student is selected.
             *
             * @returns {void}
             */
            function observeUserChange() {
                const container = document.querySelector('[data-region="user_picker"]');

                if (!container) {
                    return;
                }

                const observer = new MutationObserver(function () {
                    setTimeout(function () {
                        injectButtonIntoGrader();
                        // Clear notifications when changing users
                        $messagesContainer.empty().hide();
                    }, 300);
                });

                observer.observe(container, { childList: true, subtree: true });
            }

            /**
             * Observes the grading panel DOM and reinjects the button
             * only when real structural changes occur.
             *
             * @returns {void}
             */
            function observeGradingPanel() {

                const observer = new MutationObserver(function () {
                    injectButtonIntoGrader();
                });

                // Observe the full document body as Moodle dynamically rebuilds graders
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }
        }

        /**
         * Sets loading state on the button.
         *
         * @param {HTMLElement} button
         * @returns {Promise<void>}
         */
        async function setLoading(button) {

            const loadingText = await Str.get_string('evaluatingwithai', 'local_forum_ai');

            button.dataset.originalText = button.innerHTML;
            button.classList.add('forum-ai-btnloading');
            button.setAttribute('aria-disabled', 'true');
            button.style.pointerEvents = 'none';
            button.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' + loadingText;
        }

        /**
         * Restores the button after processing completes.
         *
         * @param {HTMLElement} button
         */
        function resetLoading(button) {

            const originalText = button.dataset.originalText || '';

            button.classList.remove('forum-ai-btnloading');
            button.removeAttribute('aria-disabled');
            button.style.pointerEvents = '';
            button.innerHTML = originalText;
        }

        /**
         * Applies a simple direct grade.
         *
         * @param {Object} data
         */
        function applySimpleGrade(data) {
            $('input[name="grade"]').val(data.grade);
        }

        /**
         * Applies grading using rubric structure.
         *
         * @param {Array} rubricData
         */
        function applyRubricGrade(rubricData) {
            rubricData.forEach(function (crit) {
                const allCriterionTitles = document.querySelectorAll('h5[id^="criterion-description-"]');

                allCriterionTitles.forEach(h5 => {

                    if (h5.textContent.trim() !== crit.criterion) {
                        return;
                    }

                    const mainContainer = h5.closest('.mb-3');
                    if (!mainContainer) {
                        return;
                    }

                    const selectedLevel = crit.levels[0];

                    const collapseDiv = mainContainer.querySelector('.collapse[role="radiogroup"]');
                    if (!collapseDiv) {
                        return;
                    }

                    const formChecks = collapseDiv.querySelectorAll('.form-check');

                    formChecks.forEach(formCheck => {
                        const label = formCheck.querySelector('label');
                        const input = formCheck.querySelector('input.level[type="radio"]');

                        if (!label || !input) {
                            return;
                        }

                        const descriptionSpan = label.querySelector('span:first-child');
                        if (!descriptionSpan) {
                            return;
                        }

                        if (descriptionSpan.textContent.trim() === selectedLevel.description) {
                            input.checked = true;
                            input.setAttribute('aria-checked', 'true');
                            input.setAttribute('tabindex', '0');
                            input.setAttribute('data-initial-value', 'true');
                            input.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    });

                    const textarea = mainContainer.querySelector('textarea[id^="advancedgrading-criteria-"][id$="-remark"]');
                    if (textarea && crit.reply) {
                        textarea.value = crit.reply;
                        textarea.setAttribute('data-initial-value', JSON.stringify(crit.reply));

                        if (textarea.hasAttribute('data-auto-rows')) {
                            textarea.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                    }
                });
            });
        }

        /**
         * Applies grading using marking guide structure.
         *
         * @param {Object} guideData
         */
        function applyGuideGrade(guideData) {

            document.querySelectorAll('[data-gradingform-guide-role="criterion"]').forEach(container => {

                const title = container.querySelector('h5').textContent.trim();

                if (!guideData[title]) {
                    return;
                }

                const result = guideData[title];
                const inputScore = container.querySelector('input[type="number"]');
                const textarea = container.querySelector('textarea');

                if (inputScore) {
                    inputScore.value = result.grade;
                }

                if (textarea) {
                    textarea.value = result.reply.join('\n');
                }
            });
        }

        return {
            init: init
        };
    });
