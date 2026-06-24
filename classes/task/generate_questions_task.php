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
 * Adhoc task that runs a STACK question generation job asynchronously.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\task;

use local_stackforge\generator;
use local_stackforge\local\pipeline;
use local_stackforge\local\scratch_importer;

/**
 * Generates the requested number of validated STACK questions into the target category. CAS validation
 * across seeds and AI retries are slow, so this never runs in a page request — it is queued and its
 * progress is written to the job table for the course page to display.
 */
class generate_questions_task extends \core\task\adhoc_task {
    /**
     * Run the job identified by the task's custom data ({jobid}).
     *
     * @return void
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');

        $data = $this->get_custom_data();
        $jobid = (int) ($data->jobid ?? 0);
        $job = $DB->get_record('local_stackforge_jobs', ['id' => $jobid]);
        if (!$job) {
            mtrace('local_stackforge: job ' . $jobid . ' not found, nothing to do.');
            return;
        }

        $job->status = 'running';
        $job->timemodified = time();
        $DB->update_record('local_stackforge_jobs', $job);

        $qids = [];
        $log = [];
        $scratch = null;
        try {
            $course = get_course($job->courseid);
            $context = \context_course::instance($job->courseid);
            $category = $DB->get_record('question_categories', ['id' => $job->categoryid], '*', MUST_EXIST);

            $mode = pipeline::resolve_mode();
            $job->mode = $mode;
            $DB->update_record('local_stackforge_jobs', $job);

            // Only the in-process path needs a scratch category. Create it inside the try so the finally
            // below always cleans it up, even on an unexpected failure.
            if ($mode === pipeline::MODE_INPROCESS) {
                $scratch = scratch_importer::create_category($context, $jobid);
                $job->scratchcatid = (int) $scratch->id;
                $DB->update_record('local_stackforge_jobs', $job);
            }

            for ($i = 0; $i < (int) $job->numrequested; $i++) {
                $res = pipeline::generate_one($job->qtype, $job->difficulty, $i, $context, $course, $scratch);
                if (!empty($res['ok']) && !empty($res['xml'])) {
                    $imported = generator::import_one($res['xml'], $category, $context, $course);
                    foreach ($imported as $qid) {
                        if (!in_array((int) $qid, $qids, true)) {
                            $qids[] = (int) $qid;
                        }
                    }
                    $log[] = '#' . ($i + 1) . ' ok (' . ($res['source'] ?? '') . ')';
                } else {
                    $log[] = '#' . ($i + 1) . ' failed: ' . ($res['reason'] ?? 'unknown');
                }
                // Persist progress after each question so the course page can show it live.
                $job->nummade = count($qids);
                $job->errors = implode("\n", $log);
                $job->timemodified = time();
                $DB->update_record('local_stackforge_jobs', $job);
            }
            $job->status = $qids ? 'done' : 'failed';
        } catch (\Throwable $e) {
            // Never leave a job stuck "running"; record the failure for the course page.
            $job->status = 'failed';
            $log[] = 'fatal: ' . $e->getMessage();
            mtrace('local_stackforge: job ' . $jobid . ' fatal: ' . $e->getMessage());
        } finally {
            if ($scratch !== null) {
                scratch_importer::delete_category((int) $scratch->id);
            }
        }

        $job->nummade = count($qids);
        $job->qids = json_encode($qids);
        $job->errors = implode("\n", $log);
        $job->timemodified = time();
        $DB->update_record('local_stackforge_jobs', $job);

        mtrace('local_stackforge: job ' . $jobid . ' ' . $job->status . ' — '
            . count($qids) . '/' . $job->numrequested . ' made.');
    }
}
