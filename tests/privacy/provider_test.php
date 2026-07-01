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
 * Privacy provider tests for local_stackforge.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Confirms the plugin's generation-job records are described, exported and deleted correctly.
 *
 * @covers \local_stackforge\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * A scratch job record for a user in a course.
     *
     * @param int $userid The owning user.
     * @param int $courseid The course.
     * @return int The job id.
     */
    private function make_job(int $userid, int $courseid): int {
        global $DB;
        return (int) $DB->insert_record('local_stackforge_jobs', (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'categoryid' => 0,
            'scratchcatid' => 0,
            'jobtype' => 'generate',
            'qtype' => 'differentiate',
            'difficulty' => 'easy',
            'numrequested' => 3,
            'nummade' => 3,
            'mode' => 'inprocess',
            'status' => 'done',
            'qids' => '[1,2,3]',
            'cmid' => 0,
            'errors' => null,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * The metadata describes the jobs table and the AI external location (the on-device model CDN is a
     * static asset fetch with no personal data, so it is not a privacy location).
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('local_stackforge'));
        $this->assertCount(2, $collection->get_collection());
    }

    /**
     * A user's jobs are reported, exported and deleted for their course context.
     *
     * @return void
     */
    public function test_export_and_delete(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_course::instance($course->id);
        $this->make_job((int) $user->id, (int) $course->id);

        // The user's context is discovered.
        $contextlist = provider::get_contexts_for_userid((int) $user->id);
        $this->assertEquals([$context->id], $contextlist->get_contextids());

        // Export writes data for the course context.
        $approved = new approved_contextlist($user, 'local_stackforge', [$context->id]);
        provider::export_user_data($approved);
        $this->assertTrue(writer::with_context($context)->has_any_data());

        // Deleting the user's data removes the row.
        provider::delete_data_for_user($approved);
        $this->assertEquals(0, $DB->count_records('local_stackforge_jobs', ['userid' => $user->id]));
    }

    /**
     * Deleting all data in a context removes every job there.
     *
     * @return void
     */
    public function test_delete_for_all_users_in_context(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_course::instance($course->id);
        $this->make_job((int) $user->id, (int) $course->id);

        provider::delete_data_for_all_users_in_context($context);
        $this->assertEquals(0, $DB->count_records('local_stackforge_jobs', ['courseid' => $course->id]));
    }
}
