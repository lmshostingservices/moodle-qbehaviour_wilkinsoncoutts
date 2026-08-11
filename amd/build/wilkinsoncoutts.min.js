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
 * JavaScript required by the wilkinsoncoutts question behaviour.
 *
 * @module     qbehaviour_wilkinsoncoutts
 * @copyright  2023 LMSACE Dev Team <lmsace.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define("qbehaviour_wilkinsoncoutts/wilkinsoncoutts",
    ["jquery", "core/str", "core/fragment", "core/local/modal/alert",
        "core/modal_events", "core/notification", "core/templates", "core/ajax"],
    (function($, Str, Fragment, ModalAlert, ModalEvents, Notification, Templates, AJAX) {

    const SELECTORS = {
        responseForm:    'form#responseform',
        prevBtn:         'input[type=submit][name="previous"]',
        navigationPanel: '#mod_quiz_navblock',
    };

    var moduleExports;

    // ── beforeunload suppression ────────────────────────────────────────────────
    //
    // v2.9 ROOT-CAUSE FIX for "Leave site?" dialog on MCQ last-page nav clicks.
    //
    // Why all previous approaches (v2.3–v2.8) failed:
    //
    //   1. `delete e.returnValue` is broken in Chrome.
    //      BeforeUnloadEvent.prototype.returnValue defaults to '' (empty string).
    //      Deleting the own property just re-exposes the prototype value, which
    //      Chrome still treats as "trigger dialog" (it treats ANY non-undefined
    //      returnValue, including '', as a request to show the dialog).
    //
    //   2. The async require(['core/form-change-checker'], cb) races window.location.href.
    //      RequireJS fires the callback asynchronously (next tick) even when the
    //      module is already in the registry, so markFormSubmitted() runs AFTER
    //      beforeunload has already fired.
    //
    //   3. The "persistent" suppressor in the constructor was registered AFTER
    //      Moodle's quiz timer AMD module (mod_quiz/timer), which registers its
    //      own beforeunload listener at page-load time during module initialisation.
    //      Listener order on window is purely registration order — capture-phase
    //      flag does not change order when all listeners share the same target
    //      (window).  So Moodle's listener fired first, set returnValue, and
    //      our listener deleted it — but delete doesn't actually clear the flag
    //      (bug 1 above).
    //
    // v2.9 SOLUTION:
    //   renderer.php outputs a tiny inline <script> in the page <head> that:
    //     (a) initialises window._wcNavSuppressBeforeUnload = false
    //     (b) registers our suppressor as the VERY FIRST beforeunload listener —
    //         before any AMD module (form-change-checker, mod_quiz/timer) loads.
    //   When navigation is about to happen we set the flag to true synchronously.
    //   Our early listener fires FIRST, calls stopImmediatePropagation() to prevent
    //   ALL subsequent handlers from running, so e.returnValue is NEVER set, and
    //   the browser shows no dialog.
    //
    // Belt-and-braces layers also applied just before window.location.href:
    //   Layer 1: M.mod_quiz.timer.Y = null  (YUI quiz timer, Moodle 3.x legacy)
    //   Layer 2: window.onbeforeunload = null
    //   Layer 3: synchronous form-change-checker clear via RequireJS module cache
    //   Layer 4: synchronous mod_quiz/timer stop via RequireJS module cache
    //   Layer 5: YUI legacy M.core_formchangechecker.form_submitted()

    /**
     * Clear form-change-checker dirty state and stop the quiz timer synchronously.
     * Uses the RequireJS internal module cache (_defined) so the call is sync —
     * no callback queuing, no race with window.location.href.
     *
     * @param {HTMLElement|null} responseForm
     */
    function wcClearDirtyState(responseForm) {
        try {
            var defined = window.require &&
                window.require.s &&
                window.require.s.contexts &&
                window.require.s.contexts._ &&
                window.require.s.contexts._.defined;

            if (defined) {
                // form-change-checker (Moodle 4.x AMD)
                var fcc = defined['core/form-change-checker'];
                if (fcc && typeof fcc.markFormSubmitted === 'function' && responseForm) {
                    fcc.markFormSubmitted(responseForm);
                }

                // mod_quiz/timer (Moodle 4.x AMD quiz timer)
                var qt = defined['mod_quiz/timer'];
                if (qt && typeof qt.stop === 'function') {
                    qt.stop();
                }
            }
        } catch (ex) {}

        // YUI legacy form-change-checker (Moodle 3.x / fallback)
        try {
            if (window.M && M.core_formchangechecker &&
                    typeof M.core_formchangechecker.form_submitted === 'function') {
                M.core_formchangechecker.form_submitted();
            }
        } catch (ex) {}
    }

    /**
     * Apply all suppression layers before any programmatic navigation.
     * Must be called synchronously (no awaits) before window.location.href.
     *
     * @param {HTMLElement|null} responseForm
     */
    function wcSuppressBeforeUnload(responseForm) {
        // Primary: flip the flag checked by our earliest-registered listener
        // (the inline <script> injected in <head> by renderer.php on last pages).
        window._wcNavSuppressBeforeUnload = true;

        // Layer 1 — disarm YUI quiz timer (Moodle 3.x / legacy)
        try {
            if (window.M && M.mod_quiz && M.mod_quiz.timer) {
                M.mod_quiz.timer.Y = null;
            }
        } catch (ex) {}

        // Layer 2 — clear window.onbeforeunload property
        window.onbeforeunload = null;

        // Layers 3-5 — synchronously clear dirty state in all known APIs
        wcClearDirtyState(responseForm);
    }

    class WilkinsonCoutts {

        // v2.6: isLastPage is now received directly from PHP (4th arg) to avoid
        // the AMD timing race where js_amd_inline body-class addition was queued
        // AFTER js_call_amd('init'), so the class was missing when the constructor
        // ran.  Body class check kept as belt-and-braces fallback only.
        constructor(contextID, duration, timeleft, isLastPage) {
            this.contextID    = contextID;
            this.responseForm = document.querySelector(SELECTORS.responseForm);
            this.explicitFinish = false;
            this.isLastPage = (isLastPage === true) ||
                document.body.classList.contains('wilkinsoncoutts-attempt-lastpage');
            this.setUpTimer(duration, timeleft);
            this.hidenavfinishbtn();

            // v2.9: Primary beforeunload suppressor is now an early inline <script>
            // injected into the page <head> by renderer.php (registered BEFORE any
            // AMD module, so guaranteed to fire first when the flag is set).
            // The old persistent listener from the constructor is removed because
            // this AMD constructor runs AFTER other AMD modules (form-change-checker,
            // mod_quiz/timer) may have registered their own listeners, making
            // registration-order-based suppression unreliable here.
        }

        hidenavfinishbtn() {
            var responseForm = document.querySelector(SELECTORS.responseForm);
            if (responseForm === null) {
                return false;
            }

            var panel = document.querySelector(SELECTORS.navigationPanel);
            var navigationNodes = panel ? panel.querySelectorAll(
                '.qnbutton.notyetanswered:not(.answersaved, .answersaved + .qnbutton)'
            ) : null;

            var elemParent = responseForm.querySelector('.que[id^="question-"]');
            if (navigationNodes !== null && elemParent !== null) {
                var split      = elemParent.id.split('-');
                var questionID = split[1];
                navigationNodes.forEach((node) => {
                    node.style.pointerEvents = 'none';
                    node.href  = '#question-' + questionID + '-' + node.dataset.quizPage;
                    node.onclick = (e) => e.preventDefault();
                });
            }

            var isPrevBtnSubmit = false;
            var prevBtn = responseForm.querySelector(SELECTORS.prevBtn);
            if (prevBtn !== null) {
                prevBtn.onclick = () => {
                    isPrevBtnSubmit = true;
                };
            }

            if (this.isLastPage) {
                this.setupFinishButtonHandler(responseForm);
                this.setupLastPageNavPanelInterceptor(responseForm);
            }

            responseForm.addEventListener('submit', (e) => {

                // v1.9 FIX: Safety net — strip any stale wilkinsoncoutts_finish marker
                // before processing this submission when the student has not explicitly
                // chosen to finish.
                if (!this.explicitFinish && !isPrevBtnSubmit) {
                    var staleMarker = responseForm.querySelector('input[name="wilkinsoncoutts_finish"]');
                    if (staleMarker) {
                        staleMarker.parentNode.removeChild(staleMarker);
                    }
                }

                // Last-page guard: when on the final question page and ALL questions
                // are answered, do NOT submit unless explicitly clicking "Finish".
                if (this.isLastPage && !this.explicitFinish && !isPrevBtnSubmit) {
                    if (responseForm.querySelector('.que.notyetanswered') === null) {
                        e.preventDefault();
                        this.activatePostCompletionNav();
                        return false;
                    }
                }

                if (responseForm.querySelector('.que.notyetanswered') === null) {
                    return true;
                }

                if (e.target.dataset.questionAnswered == 'true' || isPrevBtnSubmit) {
                    return true;
                }

                e.preventDefault();
                isPrevBtnSubmit = false;
                var formdata       = new FormData(e.currentTarget);
                var formdataString = new URLSearchParams(formdata).toString();
                this.isCompleteResponse(formdataString);
                e.target.dataset.questionAnswered = false;
                return false;
            });

            return true;
        }

        setupFinishButtonHandler(responseForm) {
            responseForm.addEventListener('click', (e) => {
                var btn = e.target;
                if (btn && btn.type === 'submit' &&
                        (btn.classList.contains('mod_quiz-next-nav') ||
                         btn.id === 'mod_quiz-next-nav')) {
                    this.explicitFinish = true;
                    if (!responseForm.querySelector('input[name="wilkinsoncoutts_finish"]')) {
                        var marker = document.createElement('input');
                        marker.type  = 'hidden';
                        marker.name  = 'wilkinsoncoutts_finish';
                        marker.value = '1';
                        responseForm.appendChild(marker);
                    }
                }
            });
        }

        /**
         * v2.9 (replaces v2.2–v2.8): On the last page, intercept nav panel <a>
         * clicks BEFORE they perform a bare GET navigation.
         *
         * Root cause of "Leave site?" (fully diagnosed v2.9):
         *   MCQ radio selection marks the form dirty via core/form-change-checker.
         *   All prior approaches used `delete e.returnValue` in a late-registered
         *   beforeunload listener — broken because deleting the own property just
         *   re-exposes the prototype default '' which Chrome still triggers on.
         *
         * v2.9 fix: renderer.php injects an inline <script> in <head> that registers
         *   our beforeunload suppressor as the VERY FIRST listener — before any AMD
         *   module can register theirs.  We set window._wcNavSuppressBeforeUnload=true
         *   SYNCHRONOUSLY the moment the user clicks, so when window.location.href
         *   triggers beforeunload, our listener fires first and stops all others.
         */
        setupLastPageNavPanelInterceptor(responseForm) {
            var self = this;
            var panel = document.querySelector(SELECTORS.navigationPanel);
            if (!panel) {
                return;
            }

            panel.addEventListener('click', function(e) {
                if (self.explicitFinish) {
                    return;
                }

                var target = e.target.closest('.qnbutton');
                if (!target) {
                    return;
                }

                var targetPage = target.dataset.quizPage;
                if (targetPage === undefined) {
                    return;
                }

                // v2.9 FIX: Set the flag SYNCHRONOUSLY — the very first thing —
                // before preventDefault, before AJAX, before anything else.
                // Our early-registered head listener checks this flag and calls
                // stopImmediatePropagation() to prevent ALL other beforeunload
                // handlers from running, so e.returnValue is never set.
                window._wcNavSuppressBeforeUnload = true;

                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();

                var attemptId = self.getAttemptId();
                if (attemptId === null) {
                    return;
                }

                var doNavigate = function() {
                    var urlParams = new URLSearchParams(window.location.search);
                    var cmid = urlParams.get('cmid');
                    if (!cmid) {
                        var cmidEl = document.querySelector('input[name="cmid"]');
                        cmid = cmidEl ? cmidEl.value : null;
                    }
                    if (cmid !== null) {
                        // Re-apply all layers synchronously (belt-and-braces: AJAX
                        // is async so we set the flag again in case anything cleared
                        // it while waiting for save/setpage responses).
                        wcSuppressBeforeUnload(responseForm);

                        window.location.href = M.cfg.wwwroot +
                            '/mod/quiz/attempt.php?attempt=' + attemptId +
                            '&cmid=' + cmid + '&page=' + targetPage + '&wc_freenav=1';
                    }
                };

                var saveData = new URLSearchParams(new FormData(responseForm)).toString();
                var savePromise = AJAX.call([{
                    methodname: 'qbehaviour_wilkinsoncoutts_save_response_state',
                    args: {contextid: self.contextID, formdata: saveData}
                }])[0];

                var setPagePromise = Fragment.loadFragment('qbehaviour_wilkinsoncoutts',
                    'set_attemptpage', self.contextID,
                    { attempt: attemptId, page: targetPage });

                $.when(savePromise, setPagePromise).then(doNavigate, doNavigate);
            }, true); // capture phase
        }

        activatePostCompletionNav() {
            this.explicitFinish = false;
            var responseForm = document.querySelector(SELECTORS.responseForm);
            if (responseForm) {
                var finishMarker = responseForm.querySelector('input[name="wilkinsoncoutts_finish"]');
                if (finishMarker) {
                    finishMarker.parentNode.removeChild(finishMarker);
                }
                responseForm.dataset.questionAnswered = 'false';
            }

            var panel = document.querySelector(SELECTORS.navigationPanel);
            if (panel) {
                panel.querySelectorAll('.qnbutton').forEach((node) => {
                    node.style.pointerEvents = '';
                    node.onclick = null;
                });
            }

            var attemptId = this.getAttemptId();
            if (attemptId !== null && moduleExports) {
                moduleExports.updateQuizNavigation(this.contextID, attemptId);
            }
        }

        getAttemptId() {
            var responseForm = document.querySelector(SELECTORS.responseForm);
            var inp = responseForm
                ? responseForm.querySelector('input[type="hidden"][name="attempt"]')
                : null;
            return inp ? inp.value : null;
        }

        isCompleteResponse(formdataString) {
            var promises = AJAX.call([{
                methodname: 'qbehaviour_wilkinsoncoutts_verify_response_state',
                args: { contextid: this.contextID, formdata: formdataString }
            }]);

            promises[0].done((result) => {

                var responseForm = document.querySelector(SELECTORS.responseForm);
                var submitForms = responseForm.querySelectorAll('input[type=submit][disabled="true"]');
                submitForms.forEach((e) => e.removeAttribute('disabled'));

                if (result == true) {
                    responseForm.dataset.questionAnswered = true;

                    if (this.isLastPage) {
                        if (this.explicitFinish) {
                            if (!responseForm.querySelector('input[name="wilkinsoncoutts_finish"]')) {
                                var marker = document.createElement('input');
                                marker.type  = 'hidden';
                                marker.name  = 'wilkinsoncoutts_finish';
                                marker.value = '1';
                                responseForm.appendChild(marker);
                            }
                        } else {
                            this.activatePostCompletionNav();
                            return true;
                        }
                    }

                    // v2.9 FIX: Apply full suppression before programmatic .click().
                    // The .click() triggers a real form submission → navigation →
                    // beforeunload.  wcSuppressBeforeUnload() sets the flag for our
                    // early head listener AND synchronously clears all dirty-state APIs.
                    wcSuppressBeforeUnload(responseForm);
                    responseForm.querySelector('input[type="submit"].mod_quiz-next-nav').click();
                } else {
                    if (M.mod_quiz.timer.Y !== null) {
                        M.mod_quiz.timer.update();
                    }
                    responseForm.querySelector('input[type="submit"].mod_quiz-next-nav') === null
                        || responseForm.querySelector('input[type="submit"].mod_quiz-next-nav')
                               .setAttribute('name', 'next');
                    ModalAlert.create({
                        title: Str.get_string('notansweredheading', 'qbehaviour_wilkinsoncoutts'),
                        body:  Str.get_string('notanswered',        'qbehaviour_wilkinsoncoutts'),
                        large: false,
                        show:  true,
                    }).catch(Notification.exception);
                }

                return true;
            }).catch(Notification.exception);
        }

        showReviewAlert() {
            var responseForm = document.querySelector(SELECTORS.responseForm);
            if (responseForm === null
                    || responseForm.querySelector('.que.notyetanswered') === null
                    || responseForm.querySelector('input[type=hidden][name=attempt]') === null) {
                return true;
            }

            var self = this;
            ModalAlert.create({
                title: Str.getString('previewalert',     'qbehaviour_wilkinsoncoutts'),
                body:  Str.getString('previewalertdesc', 'qbehaviour_wilkinsoncoutts'),
                show:  true,
            }).then((modal) => {
                modal.getRoot().on(ModalEvents.hidden, function() {
                    var attempt = responseForm
                        .querySelector('input[type=hidden][name=attempt]').value;
                    var promises = AJAX.call([{
                        methodname: 'qbehaviour_wilkinsoncoutts_reviewalert_status',
                        args: { contextid: self.contextID, attempt: attempt }
                    }]);
                    promises[0].catch(Notification.exception);
                });
            });
        }

        setUpTimer(duration, timespent) {
            if (duration <= 0 || duration === null) {
                return true;
            }
            if (timespent > duration) {
                this.showReviewAlert();
            } else {
                setTimeout(() => this.showReviewAlert(), parseInt(duration - timespent) * 1000);
            }
        }
    }

    moduleExports = {

        init: function(contextID, duration, timespent, isLastPage) {
            new WilkinsonCoutts(contextID, duration, timespent, isLastPage);
        },

        infoPopup: () => {
            ModalAlert.create({
                title: Str.getString('finishattempt',     'qbehaviour_wilkinsoncoutts'),
                body:  Str.getString('finishattemptinfo', 'qbehaviour_wilkinsoncoutts'),
                show:  true,
            });
        },

        updateFinishString: (string) => {
            var submitbtns = document.querySelector('.submitbtns');
            if (submitbtns) {
                submitbtns.style.opacity = '1';
                var nextNav = submitbtns.querySelector('#mod_quiz-next-nav');
                if (nextNav) {
                    nextNav.value = string + ' ...';
                }
            }
            $('.endtestlink').html(string + ' ...');
        },

        updateQuizNavigation: (contextID, attemptID, noPage = null) => {
            Fragment.loadFragment('qbehaviour_wilkinsoncoutts', 'update_review_navigation',
                    contextID, { attempt: attemptID, nopage: noPage })
                .then((html, js) => {
                    Templates.replaceNode('#mod_quiz_navblock .content .qn_buttons', html, js);

                    var content = document.querySelector('#mod_quiz_navblock .content');
                    if (!content) {
                        return;
                    }

                    content.addEventListener('click', function(e) {
                        if (e.target.closest('#mod_quiz_navblock .content .qn_buttons') === null) {
                            return true;
                        }

                        e.preventDefault();
                        e.stopPropagation();

                        var target = e.target.closest('.qnbutton');
                        if (!target) {
                            return;
                        }

                        var targetPage = target.dataset.quizPage;

                        // v2.9: Set suppression flag immediately on click.
                        window._wcNavSuppressBeforeUnload = true;

                        var doNavigate = function() {
                            var urlParams = new URLSearchParams(window.location.search);
                            var cmid = urlParams.get('cmid');
                            if (!cmid) {
                                var cmidEl = document.querySelector('input[name="cmid"]');
                                cmid = cmidEl ? cmidEl.value : null;
                            }

                            var saveForm = document.querySelector(SELECTORS.responseForm);
                            wcSuppressBeforeUnload(saveForm);

                            if (cmid !== null && targetPage !== undefined) {
                                window.location.href = M.cfg.wwwroot +
                                    '/mod/quiz/attempt.php?attempt=' + attemptID +
                                    '&cmid=' + cmid + '&page=' + targetPage + '&wc_freenav=1';
                            } else {
                                var href = (target.href || '').replace(
                                    /\/mod\/quiz\/review\.php/, '/mod/quiz/attempt.php'
                                );
                                href += (href.indexOf('?') !== -1 ? '&' : '?') + 'wc_freenav=1';
                                window.location.href = href;
                            }
                        };

                        var setPagePromise = Fragment.loadFragment(
                            'qbehaviour_wilkinsoncoutts', 'set_attemptpage',
                            contextID, { attempt: attemptID, page: targetPage });

                        var saveForm = document.querySelector(SELECTORS.responseForm);
                        if (saveForm) {
                            var saveData = new URLSearchParams(new FormData(saveForm)).toString();
                            var savePromise = AJAX.call([{
                                methodname: 'qbehaviour_wilkinsoncoutts_save_response_state',
                                args: { contextid: contextID, formdata: saveData }
                            }])[0];
                            $.when(savePromise, setPagePromise).then(doNavigate, doNavigate);
                        } else {
                            setPagePromise.then(doNavigate, doNavigate);
                        }
                    });
                }).catch(Notification.exception);
        }
    };

    return moduleExports;

}));
