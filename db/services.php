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
 * Qbehaviour wilkinsoncoutts external services.
 *
 * @package   qbehaviour_wilkinsoncoutts
 * @copyright  2023 LMSACE Dev Team <lmsace.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$functions = [

    'qbehaviour_wilkinsoncoutts_verify_response_state' => [
        'classname' => 'qbehaviour_wilkinsoncoutts\external',
        'methodname' => 'verify_response_state',
        'description' => 'Verify the response is completed or not',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],

    'qbehaviour_wilkinsoncoutts_reviewalert_status' => [
        'classname' => 'qbehaviour_wilkinsoncoutts\external',
        'methodname' => 'reviewalert_status',
        'description' => 'Review alert status',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],

    'qbehaviour_wilkinsoncoutts_save_response_state' => [
        'classname' => 'qbehaviour_wilkinsoncoutts\external',
        'methodname' => 'save_response_state',
        'description' => 'Save the current page responses without navigating',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
