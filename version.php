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
 * Version information for the Wilkinson Coutts question behaviour.
 *
 * @package    qbehaviour_wilkinsoncoutts
 * @copyright  2023 LMSACE Dev Team <lmsace.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'qbehaviour_wilkinsoncoutts';
$plugin->version   = 2026061900;
$plugin->requires  = 2024041600;
$plugin->maturity  = MATURITY_STABLE;
$plugin->release = '3.1';
// v2.9: ROOT-CAUSE FIX for "Leave site?" dialog on MCQ last-page nav-block clicks.
// All prior approaches (v2.3–v2.8) were fundamentally broken for two reasons:
//
// 1. `delete e.returnValue` does NOT prevent the dialog in Chrome.
//    BeforeUnloadEvent.prototype.returnValue has a default of '' (empty string).
//    Deleting the own property just re-exposes the prototype default, which Chrome
//    still treats as a trigger (Chrome triggers the dialog for any non-undefined
//    returnValue, including the empty string).
//
// 2. The async require(['core/form-change-checker'], cb) races window.location.href.
//    RequireJS fires the callback asynchronously (next tick) even when the module
//    is cached, so markFormSubmitted() runs AFTER beforeunload fires.
//    Additionally, Moodle's mod_quiz/timer AMD module (Moodle 4.x) registers its
//    own beforeunload listener at page-load time — BEFORE our AMD constructor runs
//    — so all "persistent suppressor registered in constructor" approaches were
//    too late in the registration queue to be first.
//
// Fix: renderer.php injects a tiny inline <script> via $CFG->additionalhtmlhead
// when on the last page.  The <head> script runs synchronously during HTML
// parsing — before RequireJS starts loading any AMD module — so our listener is
// guaranteed to be the VERY FIRST registered on window for beforeunload.
// The listener is gated by window._wcNavSuppressBeforeUnload (default false) and
// only calls stopImmediatePropagation() when the flag is true, preventing all
// subsequent handlers from running so e.returnValue is never set.
// The AMD module sets the flag synchronously the moment the user clicks a nav
// block button, and again just before window.location.href assignment.
// Belt-and-braces: wcSuppressBeforeUnload() also clears M.mod_quiz.timer.Y,
// window.onbeforeunload, core/form-change-checker (sync RequireJS cache access),
// mod_quiz/timer (sync stop()), and M.core_formchangechecker.form_submitted().
//
// v2.8: FIX — Layer 4: explicitly clear Moodle's form-change-checker dirty state.
// v2.7: FIX — "Leave page?" popup on multichoice questions after isCompleteResponse.
// v2.6: FIX — MCQ-only quizzes: isLastPage always false (AMD timing race).
// v2.5: FIX — Use CAPTURE phase for nav panel click interceptor.
// v2.4: FIX — Three-layer suppression of "Leave site?" dialog.
// v2.3: FIX — Suppress beforeunload dialog on free-nav redirects.
// v2.2: FIX — Four JS bugs preventing last-page back-navigation.
// v2.1: FIX — Nav panel clicks on last page now activate free-nav.
// v2.0: FIX — Persist answers before free-navigation.
// v1.9: FIX — Navigating back from last question no longer locks answers.
$plugin->supported = [403, 404];
