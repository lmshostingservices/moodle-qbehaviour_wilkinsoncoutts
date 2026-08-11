<?php
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
 * Defines the renderer for the Wilkinson Coutts behaviour.
 *
 * @package    qbehaviour_wilkinsoncoutts
 * @copyright  2023 LMSACE Dev Team <lmsace.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_quiz\output\navigation_panel_review;
use mod_quiz\quiz_attempt;

defined('MOODLE_INTERNAL') || die();


/**
 * Renderer for outputting parts of a question belonging to the Wilkinson Coutts behaviour.
 *
 * @copyright  2023 LMSACE Dev Team <lmsace.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_wilkinsoncoutts_renderer extends qbehaviour_renderer {
    /**
     * Return any HTML that needs to be included in the page's <head> when
     * questions using this model are used.
     * @param $qa the question attempt that will be displayed on the page.
     * @return string HTML fragment.
     */
    public function head_code(question_attempt $qa) {
        global $PAGE;
        $PAGE->add_body_class('wilkinsoncoutts-feedback-behaviour');
        return '';
    }

    /**
     * Activate the free-navigation JavaScript when the student arrives on any
     * page with wc_freenav=1 in the URL.
     *
     * v1.9 FIX: Removed the is_questions_completed() server-side check.
     * Previously, if the student navigated back from the last page before the
     * final answer was saved to the database (because the form submit was
     * intercepted to activate free nav), is_questions_completed() returned false
     * and updateQuizNavigation was not called — meaning the free-nav panel was
     * not maintained across pages.  Now we trust that wc_freenav=1 in the URL
     * is sufficient evidence that the student has been on the last page and
     * chosen to navigate back; we activate free nav unconditionally.
     *
     * @param quiz_attempt $attemptobj
     * @return void
     */
    public function include_review_quiznav($attemptobj) {
        global $PAGE;

        $freenav = optional_param('wc_freenav', 0, PARAM_INT);

        // v1.9: Trust wc_freenav=1 alone — drop the is_questions_completed() check
        // so free-nav persists even when the last answer has not yet been saved.
        if ($freenav && !$attemptobj->is_finished() && $attemptobj->get_quiz()->navmethod != 'free') {
            $PAGE->requires->js_call_amd('qbehaviour_wilkinsoncoutts/wilkinsoncoutts', 'updateQuizNavigation',
                ['contextid' => $PAGE->context->id, 'attempt' => $attemptobj->get_attemptid()]);
        }
    }

    /**
     * Check the user has answered for all questions in this current attempt.
     *
     * @param quiz_attempt $attemptobj
     * @return bool
     */
    public function is_questions_completed($attemptobj) {
        $completes = 0;

        foreach ($attemptobj->get_slots() as $slot) {

            if ($attemptobj->get_question_state_class($slot, false) !== 'complete') {
                $finish = false;
            }
            $completes++;
        }

        return $completes > 0 && !isset($finish);
    }

    /**
     * Include the script to prevent users access next questions without answer current question.
     *
     * @param \quiz_attempt $attemptobj
     * @return void
     */
    public function init_verification_behaviours($attemptobj) {
        global $SESSION, $PAGE, $DB, $CFG;

        if ($attemptobj->is_finished()) {
            return false;
        }

        $duration =  0;
        $timespent = 0;

        if (!property_exists($SESSION, 'qbehaviour_wilkinsoncoutts')
            || (!isset($SESSION->qbehaviour_wilkinsoncoutts['reviewalert']['attempt_'.$attemptobj->get_attempt()->id])
            || !$SESSION->qbehaviour_wilkinsoncoutts['reviewalert']['attempt_'.$attemptobj->get_attempt()->id])) {

            $timeleft = $attemptobj->get_time_left_display(time());
            $timelimit = $attemptobj->get_quiz()->timelimit;
            $timespent = $timelimit - $timeleft;

            $duration = $DB->get_field('qbehaviour_wilkinsoncoutts', 'duration', ['cmid' => $attemptobj->get_quiz()->cmid]);
        }

        // v2.6 FIX: Compute isLastPage BEFORE calling js_call_amd so the value
        // is available as a direct PHP parameter.  Previously, js_call_amd('init')
        // was queued first and js_amd_inline (body class addition) second.  Because
        // AMD module loading is async, the constructor ran before the body class
        // existed, so this.isLastPage was always false for MCQ-only quizzes —
        // meaning setupLastPageNavPanelInterceptor and the persistent beforeunload
        // suppressor were never registered.  Passing isLastPage directly removes
        // the timing dependency entirely.
        $page = optional_param('page', 0, PARAM_INT);
        $islastpage = $attemptobj->is_last_page($page);

        // v2.9 FIX: Register the beforeunload suppressor in <head> — BEFORE any AMD
        // module loads.  This guarantees our listener is the VERY FIRST registered
        // on window for the beforeunload event, ahead of Moodle's form-change-checker
        // and mod_quiz/timer AMD modules which register their own listeners at page
        // load / first form change.
        //
        // The listener is gated by window._wcNavSuppressBeforeUnload (default false)
        // so it does NOT interfere with legitimate leave-page warnings on other pages.
        // The AMD module sets the flag synchronously the moment the student clicks
        // a nav block button, then calls stopImmediatePropagation() to prevent ALL
        // subsequent handlers from running — so e.returnValue is never set and the
        // browser shows no dialog.
        //
        // Why this is in <head> (via additionalhtmlhead):
        //   AMD modules are loaded asynchronously.  Any listener registered inside
        //   an AMD module (including our own constructor) runs AFTER other AMD
        //   modules may have already registered their beforeunload listeners.
        //   additionalhtmlhead outputs a <script> tag in the page <head>, which
        //   executes synchronously during HTML parsing — before RequireJS starts
        //   loading any module.  This is the only reliable way to be first.
        if ($islastpage) {
            $suppressor = 'window._wcNavSuppressBeforeUnload=false;'
                . 'window.addEventListener("beforeunload",function (e){'
                . 'if(window._wcNavSuppressBeforeUnload){e.stopImmediatePropagation();}'
                . '},true);';
            $CFG->additionalhtmlhead .= html_writer::tag('script', $suppressor);
        }

        $PAGE->requires->js_call_amd('qbehaviour_wilkinsoncoutts/wilkinsoncoutts', 'init', [
            'contextID' => $PAGE->context->id, 'duration' => $duration, 'timespent' => $timespent,
            'isLastPage' => $islastpage]);

        if ($islastpage) {
            $PAGE->requires->js_amd_inline('document.body.classList.add("wilkinsoncoutts-attempt-lastpage");');

            if (!qbehaviour_wilkinsoncoutts_is_questions_graded($attemptobj)) {
                $PAGE->requires->js_call_amd('qbehaviour_wilkinsoncoutts/wilkinsoncoutts', 'updateFinishString', ['string' => get_string('endtest', 'qbehaviour_wilkinsoncoutts')]);
                $CFG->additionalhtmltopofbody .= html_writer::tag('style', "#page-mod-quiz-attempt .submitbtns{opacity:0;}");
            }
        }
    }
}
