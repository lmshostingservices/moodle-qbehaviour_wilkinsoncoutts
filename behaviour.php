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
 * Question behaviour where the student can submit questions.
 *
 * @package    qbehaviour_wilkinsoncoutts
 * @subpackage wilkinsoncoutts
 * @copyright  2023 LMSACE Dev Team <lmsace.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_quiz\quiz_attempt;

defined('MOODLE_INTERNAL') || die();


/**
 * Question behaviour for Wilkinson Coutts.
 *
 * Each question has a submit button next to it which the student can use to
 * submit it. Once the qustion is submitted, it is not possible for the
 * student to change their answer any more, but the student gets full feedback
 * straight away.
 *
 * @copyright  2023 LMSACE Dev Team <lmsace.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_wilkinsoncoutts extends question_behaviour_with_save {
    const IS_ARCHETYPAL = true;

    /**
     * Is compatible question.
     *
     * @param question_definition $question
     * @return boolean
     */
    public function is_compatible_question(question_definition $question) {
        return $question instanceof question_automatically_gradable;
    }

    public function can_finish_during_attempt() {
        return true;
    }

    public function get_min_fraction() {
        return $this->question->get_min_fraction();
    }

    public function get_expected_data() {
        if ($this->qa->get_state()->is_active()) {
            return array(
                'submit' => PARAM_BOOL,
                'finish' => PARAM_BOOL,
            );
        }
        return parent::get_expected_data();
    }

    public function get_state_string($showcorrectness) {

        $state = $this->qa->get_state();
        if ($state == question_state::$todo) {
            return get_string('notcomplete', 'qbehaviour_wilkinsoncoutts');
        } else {
            return parent::get_state_string($showcorrectness);
        }
    }

    public function get_right_answer_summary() {

        return $this->question->get_right_answer_summary();
    }

    /**
     * Process the aciton of questions response form submission,
     * if the question have submit variable then all the questions are triggered to complete.
     *
     * @param question_attempt_pending_step $pendingstep
     * @return void
     */
    public function process_action(question_attempt_pending_step $pendingstep) {

        if ($pendingstep->has_behaviour_var('comment')) {
            return $this->process_comment($pendingstep);
        } else if ($pendingstep->has_behaviour_var('submit')) {
            return $this->process_submit($pendingstep);
        } else if ($pendingstep->has_behaviour_var('finish')) {
            return $this->process_finish($pendingstep);
        } else {
            return $this->process_save($pendingstep);
        }
    }

    public function summarise_action(question_attempt_step $step) {

        if ($step->has_behaviour_var('comment')) {
            return $this->summarise_manual_comment($step);
        } else if ($step->has_behaviour_var('finish')) {
            return $this->summarise_finish($step);
        } else if ($step->has_behaviour_var('submit')) {
            return $this->summarise_submit($step);
        } else {
            return $this->summarise_save($step);
        }
    }

    /**
     * Perform the submit of questions.
     *
     * @param question_attempt_pending_step $pendingstep
     * @return void
     */
    public function process_submit(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        if (!$this->is_complete_response($pendingstep)) {
            $pendingstep->set_state(question_state::$invalid);

        } else {
            $response = $pendingstep->get_qt_data();
            list($fraction, $state) = $this->question->grade_response($response);
            $pendingstep->set_fraction($fraction);
            $pendingstep->set_state($state);
            $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        }
        return question_attempt::KEEP;
    }

    /**
     * Do the steps to complete the question attempt.
     *
     * @param question_attempt_pending_step $pendingstep
     * @return bool
     */
    public function process_finish(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        $response = $this->qa->get_last_step()->get_qt_data();
        if (!$this->question->is_gradable_response($response)) {
            $pendingstep->set_state(question_state::$gaveup);

        } else {
            list($fraction, $state) = $this->question->grade_response($response);
            $pendingstep->set_fraction($fraction);
            $pendingstep->set_state($state);
        }
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        return question_attempt::KEEP;
    }

    /**
     * Process the autosave of the attmept response form.
     *
     * @param question_attempt_pending_step $pendingstep
     * @return bool
     */
    public function process_save(question_attempt_pending_step $pendingstep) {
        $status = parent::process_save($pendingstep);
        return $status;
    }

    /**
     * Cause the question to be renderered. This gets the appropriate behaviour
     * renderer using {@link get_renderer()}, and adjusts the display
     * options using {@link adjust_display_options()} and then calls
     * {@link core_question_renderer::question()} to do the work.
     * @param question_display_options $options controls what should and should not be displayed.
     * @param string|null $number the question number to display.
     * @param core_question_renderer $qoutput the question renderer that will coordinate everything.
     * @param qtype_renderer $qtoutput the question type renderer that will be helping.
     * @return string HTML fragment.
     */
    public function render(question_display_options $options, $number,
            core_question_renderer $qoutput, qtype_renderer $qtoutput) {
        global $PAGE, $DB, $SESSION;

        // Here there are no update in the question attempts, use the parent content of the question.
        $output = parent::render($options, $number, $qoutput, $qtoutput);

        return $output;
    }
}
