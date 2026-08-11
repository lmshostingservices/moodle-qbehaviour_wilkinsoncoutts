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
 * Qbehaviour wilkinsoncoutts external methods.
 *
 * @package   qbehaviour_wilkinsoncoutts
 * @copyright  2023 LMSACE Dev Team <lmsace.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbehaviour_wilkinsoncoutts;

defined('MOODLE_INTERNAL') || die();

use question_engine;

require_once($CFG->libdir.'/externallib.php');

/**
 * Pulse preset external definitions.
 */
class external extends \external_api {
    /**
     * Parameters to verify the response is completed or not.
     *
     * @return void
     */
    public static function verify_response_state_parameters() {

        return new \external_function_parameters(
            [
                'contextid' => new \external_value(PARAM_INT, 'The context id for the course'),
                'formdata' => new \external_value(PARAM_RAW, 'The data from the user notes'),
            ]
        );
    }

    /**
     * Service helps to confirm the question answer is completed/answered or not.
     *
     * @param int $contextid Id for module/course context.
     * @param string $formdata Question answer form data.
     * @return bool
     */
    public static function verify_response_state(int $contextid, string $formdata) {
        global $CFG;

        require_once($CFG->dirroot . '/question/behaviour/wilkinsoncoutts/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/questionattemptstep.php');

        $postdata = [];
        parse_str($formdata, $postdata);

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
            $pendingstep = new \question_attempt_pending_step($submitteddata);
            $result = $qa->get_question()->is_complete_response($pendingstep->get_qt_data());
            // If any of the questions in the slot is not answered then prevent the next page navigation.
            if ($result == false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retuns the status of question response verification.
     *
     * @return \external_value
     */
    public static function verify_response_state_returns() {
        return new \external_value(PARAM_BOOL, 'Count of Page user notes');
    }


    /**
     * Parameters to verify the response is completed or not.
     *
     * @return void
     */
    public static function reviewalert_status_parameters() {

        return new \external_function_parameters(
            [
                'contextid' => new \external_value(PARAM_INT, 'The context id for the course'),
                'attempt' => new \external_value(PARAM_INT, 'The id of the attempt'),
            ]
        );
    }

    /**
     * Service helps to confirm the question answer is completed/answered or not.
     *
     * @param int $contextid Id for module/course context.
     * @param string $formdata Question answer form data.
     * @return bool
     */
    public static function reviewalert_status(int $contextid, int $attempt) {
        global $SESSION, $USER;

        if (property_exists($SESSION, 'qbehaviour_wilkinsoncoutts')) {
            $SESSION->qbehaviour_wilkinsoncoutts['reviewalert']['attempt_'.$attempt] = true;
        } else {
            $SESSION->qbehaviour_wilkinsoncoutts = ['reviewalert' => ['attempt_'.$attempt => true]];
        }

        return true;
    }

    /**
     * Retuns the status of review
     *
     * @return \external_value
     */
    public static function reviewalert_status_returns() {
        return new \external_value(PARAM_BOOL, 'REsult of review status');
    }

    /**
     * Parameters for saving the current page's responses.
     *
     * @return \external_function_parameters
     */
    public static function save_response_state_parameters() {
        return new \external_function_parameters(
            [
                'contextid' => new \external_value(PARAM_INT, 'The context id for the quiz module'),
                'formdata' => new \external_value(PARAM_RAW, 'The serialised response form data'),
            ]
        );
    }

    /**
     * Persist the current page's submitted responses without navigating.
     *
     * Used by the free-navigation feature so an answer typed on the last page is
     * saved before the student jumps back to a previous question. The jump is a
     * GET request that would otherwise abandon the unsaved response form. This
     * no longer depends on Moodle's optional autosave being enabled.
     *
     * @param int $contextid Quiz module context id.
     * @param string $formdata Serialised response form data.
     * @return bool True when the responses were saved.
     */
    public static function save_response_state(int $contextid, string $formdata) {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/question/behaviour/wilkinsoncoutts/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $params = self::validate_parameters(self::save_response_state_parameters(), [
            'contextid' => $contextid,
            'formdata' => $formdata,
        ]);

        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);

        $postdata = [];
        parse_str($params['formdata'], $postdata);

        if (empty($postdata['attempt'])) {
            return false;
        }

        $attemptid = (int) $postdata['attempt'];
        $cmid = null;
        $attemptobj = quiz_create_attempt_handling_errors($attemptid, $cmid);

        // Only the owner may save, and only while the attempt is still in progress.
        if ($attemptobj->get_userid() != $USER->id) {
            throw new \moodle_exception('cannotsaveattempt', 'qbehaviour_wilkinsoncoutts');
        }
        if ($attemptobj->is_finished()) {
            return false;
        }

        // Load the usage, process the submitted responses for the slots present in
        // the form data, then persist. This mirrors Moodle's own save flow.
        $quba = question_engine::load_questions_usage_by_activity($attemptobj->get_uniqueid());
        $quba->process_all_actions(time(), $postdata);
        question_engine::save_questions_usage_by_activity($quba);
        $DB->set_field('quiz_attempts', 'timemodified', time(), ['id' => $attemptid]);

        return true;
    }

    /**
     * Returns whether the responses were saved.
     *
     * @return \external_value
     */
    public static function save_response_state_returns() {
        return new \external_value(PARAM_BOOL, 'True when the current responses were saved');
    }

}
