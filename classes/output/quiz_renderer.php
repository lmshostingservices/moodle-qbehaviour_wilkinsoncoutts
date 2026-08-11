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

namespace qbehaviour_wilkinsoncoutts\output;

use html_writer;

/**
 * The main renderer for the quiz module.
 *
 * @package   qbehaviour_wilkinsoncoutts
 * @copyright  2023 LMSACE Dev Team <lmsace.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_renderer extends \mod_quiz\output\renderer {
    /**
     * Outputs the navigation block panel
     *
     * @copyright modified version of mod_quiz\output\renderer by 2011 The Open University
     * @param navigation_panel_base $panel
     */
    public function navigation_panel(\mod_quiz\output\navigation_panel_base $panel) {

        $output = '';
        // No need for the user pictures.
        /* $userpicture = $panel->user_picture();
        if ($userpicture) {
            $fullname = fullname($userpicture->user);
            if ($userpicture->size) {
                $fullname = html_writer::div($fullname);
            }
            $output .= html_writer::tag('div', $this->render($userpicture) . $fullname,
                    ['id' => 'user-picture', 'class' => 'clearfix']);
        }
        $output .= $panel->render_before_button_bits($this); */

        $bcc = $panel->get_button_container_class();
        $output .= html_writer::start_tag('div', ['class' => "qn_buttons clearfix $bcc"]);
        foreach ($panel->get_question_buttons() as $button) {
            $output .= $this->render($button);
        }
        $output .= html_writer::end_tag('div');

        // No need to include other navs.
        /* $output .= html_writer::tag('div', $panel->render_end_bits($this),
                ['class' => 'othernav']); */

        $this->page->requires->js_init_call('M.mod_quiz.nav.init', null, false,
                quiz_get_js_module());

        return $output;
    }

}
