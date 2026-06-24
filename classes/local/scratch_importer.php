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
 * Job-scoped scratch question category used to validate draft STACK questions in-process.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * Creates and tears down a temporary question category in the course context. Draft questions are
 * imported here, validated against Maxima, then deleted — the category never holds keepers.
 *
 * Cleanup is defence-in-depth: each validated draft is deleted immediately (try/finally), the whole
 * category is deleted when the job ends, and a scheduled task removes any stale scratch category a
 * PHP fatal/timeout left behind (the name carries a creation timestamp).
 */
class scratch_importer {
    /** @var string Prefix identifying scratch categories created by this plugin. */
    const PREFIX = '__stackforge_scratch_';

    /**
     * Create a fresh scratch category in the given context.
     *
     * @param \context $context The course context to create the category in.
     * @param int $jobid The owning job id (for traceability/cleanup).
     * @return \stdClass The created question_categories record.
     */
    public static function create_category(\context $context, int $jobid): \stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');

        $top = question_get_top_category($context->id, true);
        $record = new \stdClass();
        $record->name = self::PREFIX . $jobid . '_' . time();
        $record->contextid = $context->id;
        $record->info = 'Temporary STACK Forge validation scratch — safe to delete.';
        $record->infoformat = FORMAT_HTML;
        $record->stamp = make_unique_id_code();
        $record->parent = $top->id;
        $record->sortorder = 999;
        $record->idnumber = null;
        $record->id = $DB->insert_record('question_categories', $record);
        return $record;
    }

    /**
     * Import one STACK question XML into a category. Thin wrapper over generator::import_one so the
     * scratch path and the keeper path use the identical, race-free importer.
     *
     * @param string $xml The single-question STACK XML.
     * @param \stdClass $category The target category.
     * @param \context $context The category context.
     * @param \stdClass $course The course.
     * @return int[] The imported question ids (empty on failure).
     */
    public static function import(string $xml, \stdClass $category, \context $context, \stdClass $course): array {
        // generator lives in the parent namespace local_stackforge, not local_stackforge\local.
        return \local_stackforge\generator::import_one($xml, $category, $context, $course);
    }

    /**
     * Delete a single (scratch) question, best-effort.
     *
     * @param int $questionid The question id.
     * @return void
     */
    public static function delete_question(int $questionid): void {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        if ($questionid <= 0) {
            return;
        }
        try {
            question_delete_question($questionid);
        } catch (\Throwable $e) {
            debugging('local_stackforge: failed to delete scratch question ' . $questionid . ': '
                . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * The question ids currently held in a category (4.x bank schema).
     *
     * @param int $catid The category id.
     * @return int[] The question ids.
     */
    public static function question_ids(int $catid): array {
        global $DB;
        $sql = "SELECT q.id
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                 WHERE qbe.questioncategoryid = :catid";
        return array_map('intval', array_keys($DB->get_records_sql($sql, ['catid' => $catid])));
    }

    /**
     * Delete a scratch category and every question inside it, best-effort.
     *
     * @param int $catid The category id.
     * @return void
     */
    public static function delete_category(int $catid): void {
        global $DB;
        if ($catid <= 0) {
            return;
        }
        foreach (self::question_ids($catid) as $qid) {
            self::delete_question($qid);
        }
        try {
            $DB->delete_records('question_categories', ['id' => $catid]);
        } catch (\Throwable $e) {
            debugging('local_stackforge: failed to delete scratch category ' . $catid . ': '
                . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Remove any scratch category older than the given age (a fatal/timeout can bypass try/finally).
     *
     * @param int $maxagesecs Maximum age in seconds before a scratch category is considered stale.
     * @return int The number of stale categories removed.
     */
    public static function cleanup_stale(int $maxagesecs): int {
        global $DB;
        $cutoff = time() - $maxagesecs;
        $like = $DB->sql_like('name', ':pat');
        $cats = $DB->get_records_select('question_categories', $like, ['pat' => '%stackforge%scratch%'], '', 'id, name');
        $removed = 0;
        foreach ($cats as $cat) {
            // Be strict: only our own prefix, and only once the embedded timestamp is past the cutoff.
            if (strpos($cat->name, self::PREFIX) !== 0) {
                continue;
            }
            if (preg_match('/_(\d+)$/', $cat->name, $m) && (int) $m[1] < $cutoff) {
                self::delete_category((int) $cat->id);
                $removed++;
            }
        }
        return $removed;
    }
}
