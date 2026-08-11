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
 * Libarary functions defined for Question behaviour type for Wilkinson Coutts behaviour.
 *
 * @package    qbehaviour_wilkinsoncoutts
 * @copyright  2023 LMSACE Dev Team <lmsace.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_quiz\output\navigation_panel_review;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/**
 * Maintain the status of the user viewed the review alert in user session to avoid display the alert on each page refresh.
 *
 * @param array $args
 * @return void
 */
/* function qbehaviour_wilkinsoncoutts_output_fragment_reviewalert_status(array $args) {
    global $SESSION, $USER;


    $attempt = $args['attempt'];
    if (property_exists($SESSION, 'qbehaviour_wilkinsoncoutts')) {
        $SESSION->qbehaviour_wilkinsoncoutts['reviewalert']['attempt_'.$attempt] = true;
    } else {
        $SESSION->qbehaviour_wilkinsoncoutts = ['reviewalert' => ['attempt_'.$attempt => true]];
    }

    return '';
} */

/**
 * Update the review navigation page of the quiz.
 *
 * @param array $args
 * @return void
 */
function qbehaviour_wilkinsoncoutts_output_fragment_update_review_navigation(array $args) {
    global $PAGE, $CFG;

    require_once($CFG->dirroot. '/mod/quiz/locallib.php');

    $attempt = $args['attempt'];
    $nopage = $args['nopage'] ?: false;
    $attemptobj = quiz_create_attempt_handling_errors($attempt);
    $output = $PAGE->get_renderer('qbehaviour_wilkinsoncoutts', 'quiz');
    $navbc = $attemptobj->get_navigation_panel($output, navigation_panel_review::class, !$nopage ? $attemptobj->get_currentpage() : -1);
    return $navbc->content;
}

/**
 * In the sequnce navigation, moodle doesn;t alow user to access the pages,
 * In the review update the current page of the attempt by user clicked page.
 *
 * @param array $args
 * @return void
 */
function qbehaviour_wilkinsoncoutts_output_fragment_set_attemptpage(array $args) {
    global $DB, $CFG;

    require_once($CFG->dirroot. '/mod/quiz/locallib.php');

    $attempt = $args['attempt'];
    $page = $args['page'];
    // Directly set the current page to allow user to access the questions navigation.
    $DB->set_field('quiz_attempts', 'currentpage', $page, ['id' => $attempt]);

    return true;
}

/**
 * Fragment to verify the question is answered complete.
 *
 * @param array $args
 * @return void
 */
function qbehaviour_wilkinsoncoutts_output_fragment_verify_response_state(array $args) {
    global $CFG;

    require_once($CFG->dirroot. '/mod/quiz/locallib.php');

    $postdata = [];
    parse_str($args['formdata'], $postdata);

    $attemptid = $postdata['attempt'];
    $cmid = null;
    $attemptobj = quiz_create_attempt_handling_errors($attemptid, $cmid);
    $quba = question_engine::load_questions_usage_by_activity($attemptobj->get_uniqueid());

    // Get the slots in the page and get the questions attempt, then verify the question answer status.
    foreach (qbehaviour_wilkinsoncoutts_get_slots_in_request($quba, $postdata) as $slot) {
        if (!$quba->validate_sequence_number($slot, $postdata)) {
            continue;
        }
        $submitteddata = $quba->extract_responses($slot, $postdata);
        $qa = $quba->get_question_attempt($slot);
        $pendingstep = new question_attempt_pending_step($submitteddata);
        $result = $qa->get_question()->is_complete_response($pendingstep->get_qt_data());
        // If any of the questions in the slot is not answered then prevent the next page navigation.
        if ($result == false) {
            return false;
        }
    }

    return true;
}


/**
 * Get the list of slot numbers that should be processed as part of processing
 * the current request.
 * @param array $postdata optional, only intended for testing. Use this data
 * instead of the POST body data.
 * @return array of slot numbers.
 */
function qbehaviour_wilkinsoncoutts_get_slots_in_request($quba, $postdata = null) {
    // Note: we must not use "question_attempt::get_submitted_var()" because there is no attempt instance!!!
    if (is_null($postdata)) {
        $slots = optional_param('slots', null, PARAM_SEQUENCE);
    } else if (array_key_exists('slots', $postdata)) {
        $slots = clean_param($postdata['slots'], PARAM_SEQUENCE);
    } else {
        $slots = null;
    }
    if (is_null($slots)) {
        $slots = $quba->get_slots();
    } else if (!$slots) {
        $slots = array();
    } else {
        $slots = explode(',', $slots);
    }
    return $slots;
}


/**
 * Before html head print, include the js for the question verifications.
 *
 * @return void
 */
if (!class_exists('core\hook\output\before_standard_head_html_generation'))  {

    function qbehaviour_wilkinsoncoutts_before_standard_html_head() {
        global $PAGE;

        \qbehaviour_wilkinsoncoutts\eventobserver::navigation_course($PAGE->navigation, $PAGE->course);
    }
}

/**
 * Verify the quizattmept is completed
 *
 * @param [type] $attemptobj
 * @return boolean
 */
function qbehaviour_wilkinsoncoutts_is_questions_completed($attemptobj) {
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
 * Confirm the questions are graded.
 *
 * @param quiz_attempt $attemptobj
 * @return void
 */
function qbehaviour_wilkinsoncoutts_is_questions_graded($attemptobj) {
    $completes = 0;
    foreach ($attemptobj->get_slots() as $slot) {

        $state = $attemptobj->get_question_state($slot);

        if ($state == 'needsgrading') {
            $question = $attemptobj->get_question_attempt($slot);
            if ($question->get_behaviour_name() == 'manualgraded' && $attemptobj->get_question_state_class($slot, false) == 'complete') {
                $completes++;
                continue;
            }
        }

        if (!str_contains($state, 'graded')) {
            $finish = false;
        }
        $completes++;
    }

    return $completes > 0 && !isset($finish);
}

/**
 * Extend the course module form to attach the review timer settings element.
 *
 * @param mod_quiz_mod_form $instance
 * @param moodle_form $mform
 *
 * @return void
 */
function qbehaviour_wilkinsoncoutts_coursemodule_standard_elements($instance, &$mform) {

    if ($mform->elementExists('attemptonlast')) {
        $element = $mform->createElement('duration', 'wilkinsoncoutts_reviewtimer',
            get_string('configreviewtimer', 'qbehaviour_wilkinsoncoutts'));
        $mform->insertElementBefore($element, 'attemptonlast');

        $mform->hideIf('wilkinsoncoutts_reviewtimer', 'preferredbehaviour', 'neq', 'wilkinsoncoutts');
        $mform->hideIf('wilkinsoncoutts_reviewtimer', 'timelimit[enabled]', 'notchecked');
    }
}

/**
 * Store the submitted timer values in DB
 *
 * @param stdclass $data
 * @param stdclass $course
 *
 * @return stdclass
 */
function qbehaviour_wilkinsoncoutts_coursemodule_edit_post_actions($data, $course) {
    global $DB;

    $record = (object) [
        'duration' => $data->wilkinsoncoutts_reviewtimer ?? '',
        'cmid' => $data->coursemodule,
    ];

    if ($exitrecord = $DB->get_record('qbehaviour_wilkinsoncoutts', ['cmid' => $data->coursemodule])) {
        $record->id = $exitrecord->id;
        $DB->update_record('qbehaviour_wilkinsoncoutts', $record);
    } else {
        $record->timecreated = time();
        $DB->insert_record('qbehaviour_wilkinsoncoutts', $record);
    }

    return $data;
}

/**
 * Sets the value of the timer config to the form.
 *
 * @param mod_quiz_mod_form $formwrapper
 * @param moodle_form $mform
 * @return void
 */
function qbehaviour_wilkinsoncoutts_coursemodule_definition_after_data($formwrapper, $mform) {
    global $DB;

    $cm = $formwrapper->get_coursemodule();

    if (empty($cm)) {
        return false;
    }

    if ($exitrecord = $DB->get_record('qbehaviour_wilkinsoncoutts', ['cmid' => $cm->id])) {
        $mform->setDefault('wilkinsoncoutts_reviewtimer', $exitrecord->duration);
    }
}
