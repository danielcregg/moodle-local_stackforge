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
 * Privacy Subsystem implementation for local_stackforge.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * The plugin stores a record of each generation job a user requests (in local_stackforge_jobs). It
 * also discloses, to the configured AI provider, the chosen question type and difficulty when drafting,
 * neither of which is information about a user. When the on-device backend is used the model runs in the
 * author's browser and nothing is sent to any external AI provider (a smaller external footprint than the
 * server backends); the only external browser request is a one-time model download from a public CDN.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the data this plugin stores and discloses.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_stackforge_jobs', [
            'userid' => 'privacy:metadata:local_stackforge_jobs:userid',
            'courseid' => 'privacy:metadata:local_stackforge_jobs:courseid',
            'qtype' => 'privacy:metadata:local_stackforge_jobs:qtype',
            'difficulty' => 'privacy:metadata:local_stackforge_jobs:difficulty',
            'status' => 'privacy:metadata:local_stackforge_jobs:status',
            'timecreated' => 'privacy:metadata:local_stackforge_jobs:timecreated',
        ], 'privacy:metadata:local_stackforge_jobs');

        $collection->add_external_location_link('aiservice', [
            'qtype' => 'privacy:metadata:aiservice:qtype',
            'difficulty' => 'privacy:metadata:aiservice:difficulty',
        ], 'privacy:metadata:aiservice');

        // The on-device backend downloads the model from a public CDN in the browser; that is a static
        // asset fetch that sends no personal data, so (like the hinter, which likewise does not declare
        // its model CDN) it is not a privacy external-location.

        return $collection;
    }

    /**
     * Get the list of contexts that contain user data for the specified user.
     *
     * @param int $userid The user id.
     * @return contextlist The list of course contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {local_stackforge_jobs} j
                  JOIN {context} ctx ON ctx.instanceid = j.courseid AND ctx.contextlevel = :courselevel
                 WHERE j.userid = :userid";
        $contextlist->add_from_sql($sql, ['courselevel' => CONTEXT_COURSE, 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {local_stackforge_jobs} WHERE courseid = :courseid",
            ['courseid' => $context->instanceid]
        );
    }

    /**
     * Export all user data for the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }
            $records = $DB->get_records(
                'local_stackforge_jobs',
                ['courseid' => $context->instanceid, 'userid' => $userid],
                'timecreated ASC'
            );
            $data = [];
            foreach ($records as $r) {
                $data[] = [
                    'qtype' => $r->qtype,
                    'difficulty' => $r->difficulty,
                    'numrequested' => $r->numrequested,
                    'nummade' => $r->nummade,
                    'mode' => $r->mode,
                    'status' => $r->status,
                    'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
                ];
            }
            if ($data) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_stackforge')],
                    (object) ['jobs' => $data]
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The context to delete in.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof \context_course) {
            return;
        }
        $DB->delete_records('local_stackforge_jobs', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete all data for the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }
            $DB->delete_records('local_stackforge_jobs', ['courseid' => $context->instanceid, 'userid' => $userid]);
        }
    }

    /**
     * Delete data for multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved users and context.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params = array_merge(['courseid' => $context->instanceid], $inparams);
        $DB->delete_records_select('local_stackforge_jobs', "courseid = :courseid AND userid $insql", $params);
    }
}
