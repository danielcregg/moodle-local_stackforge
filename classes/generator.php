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
 * Talks to the external generation service and imports validated STACK XML into the bank.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge;

/**
 * Client for the stack-question-forge generation service plus question-bank import helpers.
 */
class generator {
    /**
     * Validate and return the admin-configured generation-service base URL.
     *
     * The endpoint is admin-only configuration (never user input). We still validate it: it must
     * be an http(s) URL with a host and no embedded credentials. Returned with any trailing slash
     * removed.
     *
     * @return string The validated base URL.
     * @throws \moodle_exception If the service is not configured or the URL is malformed.
     */
    protected static function base_url(): string {
        $base = rtrim((string) get_config('local_stackforge', 'serviceurl'), '/');
        if ($base === '') {
            throw new \moodle_exception('notconfigured', 'local_stackforge');
        }
        $parts = parse_url($base);
        if (
            $parts === false || empty($parts['scheme']) || empty($parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            throw new \moodle_exception('badendpoint', 'local_stackforge');
        }
        return $base;
    }

    /**
     * POST a JSON payload to a path on the generation service and return the decoded body.
     *
     * @param string $path Path beginning with '/', e.g. '/generate'.
     * @param array $payload Data to JSON-encode as the request body.
     * @param int $timeout Request timeout in seconds.
     * @return array The decoded JSON response (empty array if the body was not a JSON object).
     * @throws \moodle_exception On a non-200 response.
     */
    protected static function post(string $path, array $payload, int $timeout): array {
        $base = self::base_url();
        $token = (string) get_config('local_stackforge', 'apitoken');

        // The ignoresecurity option is used because the service URL is admin-configured and trusted,
        // and is often an internal address (such as a Docker service name) that Moodle's SSRF guard
        // would otherwise block. It is validated in base_url() and is never user-controlled. We also
        // pin the allowed protocols and forbid redirects so the request cannot be bounced elsewhere.
        $curl = new \curl(['ignoresecurity' => true]);
        $headers = ['Content-Type: application/json'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $curl->setHeader($headers);
        $resp = $curl->post($base . $path, json_encode($payload), [
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_IPRESOLVE' => CURL_IPRESOLVE_V4, // Moodle container has no IPv6 egress.
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_PROTOCOLS' => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            'CURLOPT_REDIR_PROTOCOLS' => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $code = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($code !== 200) {
            $detail = $code . ' ' . substr((string) $resp, 0, 200);
            throw new \moodle_exception('servicefail', 'local_stackforge', '', $detail);
        }
        $data = json_decode((string) $resp, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Ask the generation service for validated STACK questions.
     *
     * @param string $type The forge question type, e.g. 'differentiate'.
     * @param string $difficulty One of 'easy', 'medium', 'hard'.
     * @param int $count How many questions to request.
     * @return array List of question records (already oracle-validated).
     * @throws \moodle_exception
     */
    public static function generate(string $type, string $difficulty, int $count): array {
        $data = self::post('/generate', ['type' => $type, 'difficulty' => $difficulty, 'count' => $count], 180);
        return !empty($data['questions']) && is_array($data['questions']) ? $data['questions'] : [];
    }

    /**
     * Ask the generation service for the RL policy's curriculum order (skill by difficulty).
     *
     * @param int $count How many steps to request.
     * @return array List of steps in teaching order.
     * @throws \moodle_exception
     */
    public static function sequence(int $count): array {
        $data = self::post('/sequence', ['count' => $count], 30);
        return !empty($data['steps']) && is_array($data['steps']) ? $data['steps'] : [];
    }

    /**
     * Return the set of question ids currently in a question category (4.x bank schema).
     *
     * @param int $catid The question category id.
     * @return int[] The question ids in the category.
     */
    protected static function category_question_ids(int $catid): array {
        global $DB;
        $sql = "SELECT q.id
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                 WHERE qbe.questioncategoryid = :catid";
        return array_map('intval', array_keys($DB->get_records_sql($sql, ['catid' => $catid])));
    }

    /**
     * Build an RL-sequenced set: generate a validated question for each curriculum step into the
     * category (in order), then best-effort create a quiz containing them.
     *
     * @param \stdClass $course The course.
     * @param \context $context The course context.
     * @param \stdClass $category The target question category.
     * @param array $steps Curriculum steps from the RL policy.
     * @param string $name The quiz name.
     * @return array With keys made, qids, cmid and quizerror.
     */
    public static function build_rl_set(
        \stdClass $course,
        \context $context,
        \stdClass $category,
        array $steps,
        string $name
    ): array {
        global $DB, $CFG;
        // The phase 3 skill name maps to a bank/template type (only simplify differs).
        $tomap = ['simplify' => 'simplify_lowest_terms'];

        $qids = [];
        foreach ($steps as $step) {
            $type = $tomap[$step['skill']] ?? ($step['skill'] ?? '');
            $diff = $step['difficulty'] ?? 'easy';
            try {
                $qs = self::generate($type, $diff, 1);
            } catch (\Throwable $e) {
                continue;
            }
            if (empty($qs[0]['xml'])) {
                continue;
            }
            foreach (self::import_one($qs[0]['xml'], $category, $context, $course) as $qid) {
                if (!in_array($qid, $qids, true)) {
                    $qids[] = $qid;
                }
            }
        }

        $result = ['made' => count($qids), 'qids' => $qids, 'cmid' => null, 'quizerror' => null];
        if (!$qids) {
            return $result;
        }

        // Best-effort quiz creation — never let a Moodle-version edge lose the generated questions.
        try {
            require_once($CFG->dirroot . '/course/modlib.php');
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            $d = get_config('quiz');
            // The course top grade category (depth 1) is fetched directly to avoid needing the
            // grade_category class to be autoloaded.
            $gradecat = $DB->get_field('grade_categories', 'id', ['courseid' => $course->id, 'depth' => 1], IGNORE_MULTIPLE);
            $gradecatid = (int) ($gradecat ?: 0);

            $mi = new \stdClass();
            $mi->modulename = 'quiz';
            $mi->module = $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);
            $mi->course = $course->id;
            $mi->section = 0;
            $mi->visible = 1;
            $mi->visibleoncoursepage = 1;
            $mi->cmidnumber = '';
            $mi->name = $name;
            $mi->introeditor = ['text' => get_string('quizintro', 'local_stackforge'),
                'format' => FORMAT_HTML, 'itemid' => 0];
            $mi->timeopen = 0;
            $mi->timeclose = 0;
            $mi->timelimit = 0;
            $mi->overduehandling = $d->overduehandling ?? 'autosubmit';
            $mi->graceperiod = 0;
            $mi->grade = 100.0;
            $mi->grademethod = (int) ($d->grademethod ?? 1);
            $mi->attempts = 0;
            $mi->attemptonlast = 0;
            $mi->decimalpoints = (int) ($d->decimalpoints ?? 2);
            $mi->questiondecimalpoints = (int) ($d->questiondecimalpoints ?? -1);
            $mi->questionsperpage = 1;
            $mi->navmethod = $d->navmethod ?? 'free';
            $mi->shuffleanswers = (int) ($d->shuffleanswers ?? 1);
            $mi->preferredbehaviour = 'adaptive'; // STACK Check plus the AI tutor.
            $mi->canredoquestions = 0;
            $reviewfields = ['reviewattempt', 'reviewcorrectness', 'reviewmaxmarks', 'reviewmarks',
                'reviewspecificfeedback', 'reviewgeneralfeedback', 'reviewrightanswer', 'reviewoverallfeedback'];
            foreach ($reviewfields as $r) {
                $mi->$r = isset($d->$r) ? (int) $d->$r : 0x10010;
            }
            $mi->showuserpicture = 0;
            $mi->showblocks = 0;
            $mi->quizpassword = '';
            $mi->password = '';
            $mi->subnet = '';
            $mi->browsersecurity = '-';
            $mi->delay1 = 0;
            $mi->delay2 = 0;
            $mi->completion = 0;
            $mi->completionview = 0;
            $mi->completionexpected = 0;
            $mi->completionattemptsexhausted = 0;
            $mi->completionminattempts = 0;
            $mi->completionpassgrade = 0;
            $mi->completionusegrade = 0;
            $mi->feedbacktext = [['text' => '', 'format' => FORMAT_HTML]];
            $mi->feedbackboundaries = [];
            $mi->gradepass = 0;
            $mi->sumgrades = 0;
            $mi->gradecat = $gradecatid;

            $mi = add_moduleinfo($mi, $course);
            $quiz = $DB->get_record('quiz', ['id' => $mi->instance], '*', MUST_EXIST);
            foreach ($qids as $qid) {
                quiz_add_quiz_question($qid, $quiz);
            }
            quiz_update_sumgrades($quiz);
            $result['cmid'] = (int) $mi->coursemodule;
        } catch (\Throwable $e) {
            // The add_moduleinfo call runs in a delegated transaction; if it threw, roll back so we
            // do not leave a dangling transaction. The generated questions are already committed.
            if ($DB->is_transaction_started()) {
                try {
                    $DB->force_transaction_rollback();
                } catch (\Throwable $ignored) {
                    $ignored = null;
                }
            }
            $result['quizerror'] = $e->getMessage();
            debugging('local_stackforge build_rl_set quiz creation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return $result;
    }

    /**
     * Import one STACK question XML into a question category.
     *
     * Uses Moodle's standard XML question import, so the questions land in the bank exactly as a
     * manual import would. The imported ids come from the importer's own questionids list (the
     * questions this call created, race-free), with a before/after category diff as a fallback.
     *
     * @param string $xml A single quiz document containing one STACK question.
     * @param \stdClass $category The target question category.
     * @param \context $context The category/course context.
     * @param \stdClass $course The course.
     * @return int[] The ids of the questions imported (empty on failure / nothing imported).
     */
    public static function import_one(string $xml, \stdClass $category, \context $context, \stdClass $course): array {
        global $CFG;
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        // Defence in depth: the XML comes from our own generation service, but don't import an
        // unexpected shape — cap the size and require exactly one STACK question, no other types.
        if (
            strlen($xml) > 200000
            || preg_match_all('/<question\s+type="stack"/i', $xml) !== 1
            || preg_match_all('/<question\s+type="(?!stack|category)[^"]*"/i', $xml) > 0
        ) {
            return [];
        }

        $before = self::category_question_ids((int) $category->id);

        $tmp = make_request_directory() . '/stackforge.xml';
        file_put_contents($tmp, $xml);

        $qformat = new \qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts([$context]);
        $qformat->setCourse($course);
        $qformat->setFilename($tmp);
        $qformat->setRealfilename('stackforge.xml');
        $qformat->setMatchgrades('error');
        $qformat->setCatfromfile(false); // Import into the chosen category, not one named in the file.
        $qformat->setContextfromfile(false);
        $qformat->setStoponerror(true);

        ob_start(); // The importprocess() call echoes progress HTML; swallow it.
        try {
            $ok = $qformat->importpreprocess()
                && $qformat->importprocess()
                && $qformat->importpostprocess();
        } catch (\Throwable $e) {
            $ok = false;
        }
        ob_end_clean();
        if (!$ok) {
            return [];
        }
        // The importer records exactly the questions this call created — authoritative and race-free.
        $ids = array_map('intval', $qformat->questionids ?? []);
        if ($ids) {
            return $ids;
        }
        // Fallback for any edge where the importer did not populate questionids.
        $after = self::category_question_ids((int) $category->id);
        return array_values(array_diff($after, $before));
    }
}
