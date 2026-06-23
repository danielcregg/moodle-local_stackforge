<?php
// Talks to the external generation service and imports the validated XML into the question bank.
namespace local_stackforge;

defined('MOODLE_INTERNAL') || die();

class generator {

    /**
     * Ask the generation service for validated STACK questions.
     *
     * @return array list of ['name','type','difficulty','source','xml'] (already oracle-validated)
     * @throws \moodle_exception
     */
    public static function generate(string $type, string $difficulty, int $count): array {
        $base = rtrim((string)get_config('local_stackforge', 'serviceurl'), '/');
        $token = (string)get_config('local_stackforge', 'apitoken');
        if ($base === '') {
            throw new \moodle_exception('notconfigured', 'local_stackforge');
        }
        $payload = json_encode(['type' => $type, 'difficulty' => $difficulty, 'count' => $count]);

        // ignoresecurity: the service URL is an admin-configured, trusted endpoint (often an
        // internal address like http://generate:8092), so bypass Moodle's block on private hosts
        // for this one call. It is never user-controlled.
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);
        $resp = $curl->post($base . '/generate', $payload, [
            'CURLOPT_TIMEOUT' => 180,
            'CURLOPT_IPRESOLVE' => CURL_IPRESOLVE_V4,   // Moodle container has no IPv6 egress
        ]);
        $code = (int)($curl->get_info()['http_code'] ?? 0);
        if ($code !== 200) {
            throw new \moodle_exception('servicefail', 'local_stackforge', '',
                $code . ' ' . substr((string)$resp, 0, 200));
        }
        $data = json_decode((string)$resp, true);
        return (is_array($data) && !empty($data['questions'])) ? $data['questions'] : [];
    }

    /** Ask the generation service for the RL policy's curriculum order (skill x difficulty). */
    public static function sequence(int $count): array {
        $base = rtrim((string) get_config('local_stackforge', 'serviceurl'), '/');
        $token = (string) get_config('local_stackforge', 'apitoken');
        if ($base === '') {
            throw new \moodle_exception('notconfigured', 'local_stackforge');
        }
        $curl = new \curl(['ignoresecurity' => true]);
        $h = ['Content-Type: application/json'];
        if ($token !== '') { $h[] = 'Authorization: Bearer ' . $token; }
        $curl->setHeader($h);
        $resp = $curl->post($base . '/sequence', json_encode(['count' => $count]),
            ['CURLOPT_TIMEOUT' => 30, 'CURLOPT_IPRESOLVE' => CURL_IPRESOLVE_V4]);
        if ((int) ($curl->get_info()['http_code'] ?? 0) !== 200) {
            throw new \moodle_exception('servicefail', 'local_stackforge', '', substr((string) $resp, 0, 200));
        }
        $d = json_decode((string) $resp, true);
        return (is_array($d) && !empty($d['steps'])) ? $d['steps'] : [];
    }

    /** The newest question id in a category (works on the Moodle 4.x question-bank schema). */
    public static function latest_question_id(int $catid): int {
        global $DB;
        $sql = "SELECT MAX(q.id)
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                 WHERE qbe.questioncategoryid = :catid";
        return (int) $DB->get_field_sql($sql, ['catid' => $catid]);
    }

    /**
     * Build an RL-sequenced set: generate a validated question for each curriculum step into the
     * category (in order), then BEST-EFFORT create a quiz containing them. Returns
     * ['made'=>n, 'qids'=>[...], 'cmid'=>int|null, 'quizerror'=>string|null].
     */
    public static function build_rl_set(\stdClass $course, \context $context, \stdClass $category,
                                        array $steps, string $name): array {
        global $DB, $CFG;
        // phase3 skill name -> bank/template type (only simplify differs).
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
            if (empty($qs[0]['xml']) || !self::import_one($qs[0]['xml'], $category, $context, $course)) {
                continue;
            }
            $qid = self::latest_question_id((int) $category->id);
            if ($qid && !in_array($qid, $qids, true)) { $qids[] = $qid; }
        }

        $result = ['made' => count($qids), 'qids' => $qids, 'cmid' => null, 'quizerror' => null];
        if (!$qids) { return $result; }

        // Best-effort quiz creation — never let a Moodle-version edge lose the generated questions.
        try {
            require_once($CFG->dirroot . '/course/modlib.php');
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            $d = get_config('quiz');
            // Course's top grade category (depth 1) — fetched directly to avoid needing the
            // grade_category class to be loaded.
            $gradecatid = (int) ($DB->get_field('grade_categories', 'id',
                ['courseid' => $course->id, 'depth' => 1], IGNORE_MULTIPLE) ?: 0);

            $mi = new \stdClass();
            $mi->modulename = 'quiz';
            $mi->module = $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);
            $mi->course = $course->id;
            $mi->section = 0;
            $mi->visible = 1; $mi->visibleoncoursepage = 1; $mi->cmidnumber = '';
            $mi->name = $name;
            $mi->introeditor = ['text' => 'Auto-built from the Phase 3 RL teaching policy: the questions '
                . 'follow the policy\'s easy→hard curriculum.', 'format' => FORMAT_HTML, 'itemid' => 0];
            $mi->timeopen = 0; $mi->timeclose = 0; $mi->timelimit = 0;
            $mi->overduehandling = $d->overduehandling ?? 'autosubmit'; $mi->graceperiod = 0;
            $mi->grade = 100.0; $mi->grademethod = (int) ($d->grademethod ?? 1);
            $mi->attempts = 0; $mi->attemptonlast = 0;
            $mi->decimalpoints = (int) ($d->decimalpoints ?? 2);
            $mi->questiondecimalpoints = (int) ($d->questiondecimalpoints ?? -1);
            $mi->questionsperpage = 1; $mi->navmethod = $d->navmethod ?? 'free';
            $mi->shuffleanswers = (int) ($d->shuffleanswers ?? 1);
            $mi->preferredbehaviour = 'adaptive';   // STACK Check + the AI tutor
            $mi->canredoquestions = 0;
            foreach (['reviewattempt', 'reviewcorrectness', 'reviewmaxmarks', 'reviewmarks',
                      'reviewspecificfeedback', 'reviewgeneralfeedback', 'reviewrightanswer',
                      'reviewoverallfeedback'] as $r) {
                $mi->$r = isset($d->$r) ? (int) $d->$r : 0x10010;
            }
            $mi->showuserpicture = 0; $mi->showblocks = 0;
            $mi->quizpassword = ''; $mi->password = ''; $mi->subnet = ''; $mi->browsersecurity = '-';
            $mi->delay1 = 0; $mi->delay2 = 0;
            $mi->completion = 0; $mi->completionview = 0; $mi->completionexpected = 0;
            $mi->completionattemptsexhausted = 0; $mi->completionminattempts = 0;
            $mi->completionpassgrade = 0; $mi->completionusegrade = 0;
            $mi->feedbacktext = [['text' => '', 'format' => FORMAT_HTML]];
            $mi->feedbackboundaries = [];
            $mi->gradepass = 0; $mi->sumgrades = 0;
            $mi->gradecat = $gradecatid;

            $mi = add_moduleinfo($mi, $course);
            $quiz = $DB->get_record('quiz', ['id' => $mi->instance], '*', MUST_EXIST);
            foreach ($qids as $qid) { quiz_add_quiz_question($qid, $quiz); }
            quiz_update_sumgrades($quiz);
            $result['cmid'] = (int) $mi->coursemodule;
        } catch (\Throwable $e) {
            // add_moduleinfo runs in a delegated transaction; if it threw, roll back so we don't
            // leave a dangling transaction. The generated questions are already committed.
            if ($DB->is_transaction_started()) {
                try { $DB->force_transaction_rollback(); } catch (\Throwable $ignored) {
                    $ignored = null;
                }
            }
            $result['quizerror'] = $e->getMessage();
            debugging('local_stackforge build_rl_set quiz creation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return $result;
    }

    /**
     * Import one <quiz>…</quiz> STACK XML into a question category. Returns true on success.
     *
     * Uses Moodle's standard XML question import, so the questions land in the bank exactly as a
     * manual "Import" would — fully usable in quizzes, including the STACK question type.
     */
    public static function import_one(string $xml, \stdClass $category, \context $context, \stdClass $course): bool {
        global $CFG;
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        // Defence in depth: the XML comes from our own generation service, but don't import an
        // unexpected shape — cap the size and require exactly one STACK question, no other types.
        if (strlen($xml) > 200000
                || preg_match_all('/<question\s+type="stack"/i', $xml) !== 1
                || preg_match_all('/<question\s+type="(?!stack|category)[^"]*"/i', $xml) > 0) {
            return false;
        }

        $tmp = make_request_directory() . '/stackforge.xml';
        file_put_contents($tmp, $xml);

        $qformat = new \qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts([$context]);
        $qformat->setCourse($course);
        $qformat->setFilename($tmp);
        $qformat->setRealfilename('stackforge.xml');
        $qformat->setMatchgrades('error');
        $qformat->setCatfromfile(false);       // import into the chosen category, not one named in the file
        $qformat->setContextfromfile(false);
        $qformat->setStoponerror(true);

        ob_start();   // importprocess() echoes progress HTML; swallow it
        try {
            $ok = $qformat->importpreprocess()
                && $qformat->importprocess()
                && $qformat->importpostprocess();
        } catch (\Throwable $e) {
            $ok = false;
        }
        ob_end_clean();
        return (bool)$ok;
    }
}
