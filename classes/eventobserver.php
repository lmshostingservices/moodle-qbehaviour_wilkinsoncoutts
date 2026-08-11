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
 * Define event & hooks observers of quiz attempt.
 *
 * @package   qbehaviour_wilkinsoncoutts
 * @copyright  2023 LMSACE Dev Team <lmsace.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbehaviour_wilkinsoncoutts;

require_once($CFG->dirroot . '/question/behaviour/wilkinsoncoutts/lib.php');

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use question_engine;

class eventobserver {
    /**
     * Attempt of the quiz is updated, Confirm the user complete all the questions and submit the finish attempt.
     * Then get all the questions in the attempt and finish questions.
     *
     * NOTE: This observer is registered globally for mod_quiz\event\attempt_updated.
     * It MUST check that the quiz is actually using wilkinsoncoutts behaviour before
     * doing anything — otherwise it incorrectly auto-finishes questions in Deferred
     * Feedback (and every other behaviour), locking all answers the moment the student
     * lands on the last page with all previous questions answered.
     *
     * FIX-EXPLICIT-FINISH (v1.4): finish_all_questions() is now only called when the
     * student has explicitly clicked "Finish and Start Review".  The JS sets a hidden
     * form field (wilkinsoncoutts_finish=1) for that click, and this observer checks
     * for that parameter before locking answers.  Without this guard, any form
     * submission from the last page (autosave, incidental nav) would lock all
     * question responses before the student had a chance to use the navigation grid
     * to review and modify their answers.
     *
     * @param stdclass $event
     * @return void
     */
    public static function attempt_updated($event) {
        $attemptid = $event->objectid;

        if ($attemptid) {

            $attemptobj = quiz_attempt::create($attemptid);

            // FIX-DEFERRED-FEEDBACK-LOCK (v1.2): Only auto-finish questions for quizzes
            // that are actually using the wilkinsoncoutts behaviour.  Previously this
            // observer ran for every quiz type — including Deferred Feedback — which
            // caused finish_all_questions() to be called (locking all answers read-only)
            // the moment a student navigated to the last page after answering all
            // earlier questions, even though Deferred Feedback should allow free editing
            // until the student explicitly clicks "Submit all and finish".
            $behaviour = $attemptobj->get_quiz()->preferredbehaviour;
            if ($behaviour !== 'wilkinsoncoutts') {
                return true;
            }

            $page = $event->other['page'];

            // Verify the finish attempt is clicked.
            // Sometimes in the last page user fills the answers and clicks the preview button,
            // it will submits the question and finish all the questions. To prevent this verify the next button is clicked.
            $isprevious = optional_param('previous', null, PARAM_ALPHANUMEXT);

            // FIX-EXPLICIT-FINISH (v1.4): Only proceed when the student has deliberately
            // clicked "Finish and Start Review".  The JS handler adds a hidden input
            // (wilkinsoncoutts_finish=1) to the form for that specific click.  Without
            // this check any form submission from the last page — including the one that
            // fires when the student first answers the final question and submits — would
            // immediately lock all answers, preventing back-navigation and answer editing.
            $explicit_finish = optional_param('wilkinsoncoutts_finish', null, PARAM_INT);

            if ($attemptobj->is_last_page($page) && $isprevious === null && $explicit_finish) {

                // Usage id.
                $usageid = $attemptobj->get_uniqueid();

                // Verify all questions are answered.
                $finish = true;
                foreach ($attemptobj->get_slots() as $slot) {
                    if ($attemptobj->get_question_state_class($slot, false) == 'notyetanswered') {
                        $finish = false;
                    }
                }

                // All questions are answered and not completed already then finish all questions.
                if ($finish && !qbehaviour_wilkinsoncoutts_is_questions_graded($attemptobj)) {
                    self::finish_all_questions($usageid, $attemptid);
                }
            }

        }
    }

    /**
     * Start the finish of all questions in this attempt. Fetch the question usage and get slots.
     *
     * @return void
     */
    public static function finish_all_questions($usageid, $attemptid) {
        global $SESSION;

        $quba = question_engine::load_questions_usage_by_activity($usageid);

        foreach ($quba->get_slots() as $slot) {
            $quba->finish_question($slot, time());
            question_engine::save_questions_usage_by_activity($quba);
        }

        $SESSION->qbehaviour_wilkinsoncoutts = ['finishattemptinfo' => [$attemptid => true]];
    }


    /**
     * Hook observer
     *
     * Before html head print, include the js for the question verifications.
     *
     * @return void
     */
    public static function before_standard_head_html() {
        global $PAGE;

        self::navigation_course($PAGE->navigation, $PAGE->course);
    }

    /**
     * Extend the course navigation to update the submission confirmation strings,
     * display the instrucations to reivew the attempts after finish the attempt and start the review button clicked.
     *
     * @param navigation $navigation
     * @param stdclass $course
     * @return void
     */
    public static function navigation_course($navigation, $course) {
        global $PAGE, $SESSION;

        if ($PAGE->pagetype == 'mod-quiz-attempt') {
            $behaviour = quiz_settings::create_for_cmid($PAGE->cm->id)->get_quiz()->preferredbehaviour;

            if ($behaviour != 'wilkinsoncoutts') {
                return true;
            }

            $renderer = $PAGE->get_renderer('qbehaviour_wilkinsoncoutts');
            $attemptid = optional_param('attempt', null, PARAM_INT);

            if ($attemptid === null) {
                return false;
            }

            $attemptobj = quiz_attempt::create($attemptid);

            // Initialize the js to verification of questions answered status,
            // and prvent users to access next pages without answer current questions.
            $renderer->init_verification_behaviours($attemptobj);
            // Update the review quiz navigation for the sequential format.
            $renderer->include_review_quiznav($attemptobj);
        }

        // Check the page is quiz summary page then init the custom confirmation script to modify the confirmation strings.
        if ($PAGE->bodyid == 'page-mod-quiz-summary') {
            $attemptid = optional_param('attempt', null, PARAM_INT);

            $behaviour = quiz_settings::create_for_cmid($PAGE->cm->id)->get_quiz()->preferredbehaviour;
            if ($behaviour != 'wilkinsoncoutts') {
                return true;
            }

            $attemptobj = quiz_attempt::create($attemptid);

            $completed =  qbehaviour_wilkinsoncoutts_is_questions_completed($attemptobj);
            if ($attemptobj->get_state() == \mod_quiz\quiz_attempt::IN_PROGRESS && $completed) {
                $totalunanswered = 0;
                if ($attemptobj->get_quiz()->navmethod == 'free') {
                    // Only count the unanswered question if the navigation method is set to free.
                    $totalunanswered = $attemptobj->get_number_of_unanswered_questions();
                } else {
                $PAGE->requires->js_call_amd('qbehaviour_wilkinsoncoutts/wilkinsoncoutts', 'updateQuizNavigation',
                    ['contextid' => $PAGE->context->id, 'attempt' => $attemptid, 'nopage' => true]);
                }

                $PAGE->requires->js_call_amd('qbehaviour_wilkinsoncoutts/submission_confirmation', 'init', [$totalunanswered]);

                // Verify the user answered all the questions and fninsh the attempt, starts the review. then display the instructins to review.
                if (isset($SESSION->qbehaviour_wilkinsoncoutts) && (
                    isset($SESSION->qbehaviour_wilkinsoncoutts['finishattemptinfo']) &&
                    isset($SESSION->qbehaviour_wilkinsoncoutts['finishattemptinfo'][$attemptid]) &&
                    $SESSION->qbehaviour_wilkinsoncoutts['finishattemptinfo'][$attemptid])) {
                    // Display the info of the review starts.
                    $PAGE->requires->js_call_amd('qbehaviour_wilkinsoncoutts/wilkinsoncoutts', 'infoPopup', ['contextID' => $PAGE->context->id]);

                    $SESSION->qbehaviour_wilkinsoncoutts['finishattemptinfo'][$attemptid] = false;
                    unset($SESSION->qbehaviour_wilkinsoncoutts['finishattemptinfo'][$attemptid]);
                }
            }
        }

    }
}
