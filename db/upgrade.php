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
 * Upgrade steps for qbehaviour_wilkinsoncoutts.
 *
 * @param int $oldversion
 * @return bool
 * @package    qbehaviour_wilkinsoncoutts
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
function xmldb_qbehaviour_wilkinsoncoutts_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if (!$dbman->table_exists('qbehaviour_wilkinsoncoutts')) {
        $table = new xmldb_table('qbehaviour_wilkinsoncoutts');
        $table->add_field('id',          XMLDB_TYPE_INTEGER, '18', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null, null);
        $table->add_field('cmid',        XMLDB_TYPE_INTEGER, '18', null, XMLDB_NOTNULL, null, null, 'id');
        $table->add_field('duration',    XMLDB_TYPE_INTEGER, '18', null, XMLDB_NOTNULL, null, null, 'cmid');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '18', null, XMLDB_NOTNULL, null, null, 'duration');
        $table->add_key('id',   XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('cmid', XMLDB_KEY_UNIQUE,  ['cmid']);
        $dbman->create_table($table);
    }

    if ($oldversion < 2026060300) {
        upgrade_plugin_savepoint(true, 2026060300, 'qbehaviour', 'wilkinsoncoutts');
    }

    if ($oldversion < 2026060400) {
        upgrade_plugin_savepoint(true, 2026060400, 'qbehaviour', 'wilkinsoncoutts');
    }

    // v1.9 (2026061000001): FIX — Three-part fix to prevent navigation from last
    // question locking answers. JS changes only + renderer.php relaxed
    // is_questions_completed() check. No DB schema changes.
    if ($oldversion < 2026061000001) {
        upgrade_plugin_savepoint(true, 2026061000001, 'qbehaviour', 'wilkinsoncoutts');
    }

    // v2.0 (2026061100001): FIX — save current page answers before free-nav GET.
    // JS + new save_response_state web service only. No DB schema changes.
    if ($oldversion < 2026061100001) {
        upgrade_plugin_savepoint(true, 2026061100001, 'qbehaviour', 'wilkinsoncoutts');
    }

    // v2.1 (2026061500001): FIX — Nav panel clicks on last page activate free-nav.
    // JS changes only. No DB schema changes.
    if ($oldversion < 2026061500001) {
        upgrade_plugin_savepoint(true, 2026061500001, 'qbehaviour', 'wilkinsoncoutts');
    }

    // v2.2 (2026061700001): FIX — Four JS bugs: stale notyetanswered guard removed
    // from last-page nav interceptor; set_attemptpage added to interceptor; race
    // condition in updateQuizNavigation fixed via $.when(); stopPropagation added to
    // prevent double-handler firing. JS changes only. No DB schema changes.
    if ($oldversion < 2026061700001) {
        upgrade_plugin_savepoint(true, 2026061700001, 'qbehaviour', 'wilkinsoncoutts');
    }

    // v2.8 (2026061900001): FIX — "Leave page?" dialog when navigating via grid after
    // answering a multichoice question on the last page.  Root cause: MCQ radio-button
    // selection marks the form dirty in M.core_formchangechecker but there is no
    // autosave to clear it (unlike essay questions).  The existing three-layer
    // beforeunload suppressor was insufficient in some browser/Moodle combinations.
    // Fix: added Layer 4 — explicitly call markFormSubmitted (Moodle 4.x AMD) and
    // M.core_formchangechecker.form_submitted (Moodle 3.x YUI) in both doNavigate
    // functions before setting window.location.href.  JS changes only.
    if ($oldversion < 2026061900001) {
        upgrade_plugin_savepoint(true, 2026061900001, 'qbehaviour', 'wilkinsoncoutts');
    }

    return true;
}
