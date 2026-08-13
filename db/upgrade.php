<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_qbehaviour_wilkinsoncoutts_upgrade($oldversion) {
    if ($oldversion < 2026061900) {
        upgrade_plugin_savepoint(true, 2026061900, 'qbehaviour', 'wilkinsoncoutts');
    }
    return true;
}
