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
 * Upgrade steps for local_stackforge.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute the local_stackforge upgrade from the given old version.
 *
 * @param int $oldversion The currently installed plugin version.
 * @return bool Always true on success.
 */
function xmldb_local_stackforge_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026062401) {
        // Introduce the asynchronous generation job table for the in-process pipeline.
        $table = new xmldb_table('local_stackforge_jobs');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('scratchcatid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('jobtype', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'generate');
        $table->add_field('qtype', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('difficulty', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'easy');
        $table->add_field('numrequested', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('nummade', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('mode', XMLDB_TYPE_CHAR, '16', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'queued');
        $table->add_field('qids', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('errors', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('useridcourseid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026062401, 'local', 'stackforge');
    }

    if ($oldversion < 2026062800) {
        // The new "AI backend" setting defaults to 'auto'. Pin sites already using this plugin's own
        // AI key to 'own' so they are not silently switched to core AI for expression drafting.
        $current = get_config('local_stackforge', 'aibackend');
        if ($current === false || $current === '') {
            $hasownkey = trim((string) get_config('local_stackforge', 'ai_key')) !== '';
            set_config('aibackend', $hasownkey ? 'own' : 'auto', 'local_stackforge');
        }
        upgrade_plugin_savepoint(true, 2026062800, 'local', 'stackforge');
    }

    if ($oldversion < 2026070105) {
        // Add the optional on-device (in-browser WebLLM) generation backend and the shared pre/post
        // pipeline. This is additive and inert by default: existing explicit aibackend values are left
        // untouched, so no site is silently switched to on-device. Only seed the new on-device model
        // default if it is unset, so the browser knows which model to run when on-device is selected.
        if (get_config('local_stackforge', 'ondevicemodel') === false) {
            set_config('ondevicemodel', 'gemma-2-2b-it-q4f16_1-MLC', 'local_stackforge');
        }
        if (get_config('local_stackforge', 'errormemory') === false) {
            set_config('errormemory', 1, 'local_stackforge');
        }
        upgrade_plugin_savepoint(true, 2026070105, 'local', 'stackforge');
    }

    return true;
}
