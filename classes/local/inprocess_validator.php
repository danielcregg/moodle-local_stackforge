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
 * In-process validation of draft STACK questions against Moodle's own qtype_stack + Maxima.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * The oracle, in-process. Imports a draft question into a scratch category, instantiates it across
 * every deployed seed, rejects any runtime CAS error (so a grammar-valid but non-elementary integrand
 * cannot slip through), discovers the terminal answer notes, bakes them in, and proves the exported
 * question's own question-tests pass via Moodle's authoritative test_question() path.
 *
 * Built against the verified qtype_stack 4.12 API (see the design spec). All Maxima access is wrapped
 * so any CAS error becomes a clean rejection rather than a fatal.
 */
class inprocess_validator {
    /**
     * Feature-probe: is the in-process path supported by the installed qtype_stack?
     *
     * @return array [bool $ok, string $reason].
     */
    public static function inprocess_supported(): array {
        global $CFG;
        $qtype = \question_bank::get_qtype('stack', false);
        if (!$qtype) {
            return [false, 'qtype_stack is not installed'];
        }
        if (!method_exists($qtype, 'load_question_tests')) {
            return [false, 'qtype_stack::load_question_tests is missing'];
        }
        $testfile = $CFG->dirroot . '/question/type/stack/stack/questiontest.php';
        if (!is_readable($testfile)) {
            return [false, 'qtype_stack questiontest.php is missing'];
        }
        require_once($testfile);
        if (!class_exists('stack_question_test') || !method_exists('stack_question_test', 'compute_response')) {
            return [false, 'stack_question_test::compute_response is missing'];
        }
        return [true, ''];
    }

    /**
     * Validate a structured question in-process and return the final (notes-baked) XML.
     *
     * @param array $q The structured question (from a template).
     * @param \context $context The course context to validate in.
     * @param \stdClass $course The course.
     * @param \stdClass $scratchcat The job's scratch category (draft + final copies live here briefly).
     * @return array ok|xml|reason|name|type|difficulty|seeds|tests.
     */
    public static function validate(array $q, \context $context, \stdClass $course, \stdClass $scratchcat): array {
        global $CFG;
        require_once($CFG->dirroot . '/question/type/stack/stack/questiontest.php');

        $seeds = array_values(array_map('intval', $q['deployedSeeds'] ?? []));
        if (!$seeds) {
            return self::fail('question has no deployed seeds');
        }

        // 1. Build and import the draft into the scratch category.
        $draftxml = question_xml::build($q);
        $draftids = scratch_importer::import($draftxml, $scratchcat, $context, $course);
        if (count($draftids) !== 1) {
            foreach ($draftids as $id) {
                scratch_importer::delete_question((int) $id);
            }
            return self::fail('draft import did not create exactly one question');
        }
        $draftqid = (int) $draftids[0];

        try {
            // 2. Per seed: instantiate, reject runtime errors, discover terminal notes per (test, prt).
            $notes = [];
            foreach ($seeds as $seed) {
                $question = \question_bank::load_question($draftqid);
                $question->options->set_option('decimals', '.');
                $question->seed = $seed;
                $quba = \question_engine::make_questions_usage_by_activity('qtype_stack', $context);
                $quba->set_preferred_behaviour('adaptive');
                $qaslot = $quba->add_question($question, $question->defaultmark);
                try {
                    $quba->start_question($qaslot, $seed);
                    // Force full evaluation of the question variables and the question-text CasText.
                    $question->get_question_summary();
                } catch (\Throwable $e) {
                    return self::fail('instantiation error (seed ' . $seed . '): ' . self::short($e->getMessage()));
                }
                if (!empty($question->runtimeerrors)) {
                    return self::fail('runtime CAS error (seed ' . $seed . '): '
                        . self::short(implode('; ', array_keys($question->runtimeerrors))));
                }
                $bulk = trim((string) $question->validate_for_bulk($context));
                if ($bulk !== '') {
                    return self::fail('validate_for_bulk (seed ' . $seed . '): ' . self::short($bulk));
                }
                $ver = trim((string) $question->validate_against_stackversion($context));
                if ($ver !== '') {
                    return self::fail('stackversion check (seed ' . $seed . '): ' . self::short($ver));
                }

                foreach (($q['tests'] ?? []) as $i => $test) {
                    $response = \stack_question_test::compute_response($question, $test['inputs'] ?? []);
                    foreach (array_keys($test['expected'] ?? []) as $prtname) {
                        $res = $question->get_prt_result($prtname, $response, false);
                        $errors = $res->get_errors();
                        if (!empty($errors)) {
                            return self::fail('PRT runtime error (seed ' . $seed . ', tc ' . ($i + 1) . ', '
                                . $prtname . '): ' . self::short(implode('; ', $errors)));
                        }
                        $note = self::canonical_note($res->get_answernotes());
                        $notes[$i][$prtname][$note] = true;
                    }
                }
            }

            // 3. Bake the discovered notes: every seed must agree, disagreement is fatal.
            foreach (($q['tests'] ?? []) as $i => $test) {
                foreach (array_keys($test['expected'] ?? []) as $prtname) {
                    $seen = array_keys($notes[$i][$prtname] ?? []);
                    if (count($seen) !== 1 || $seen[0] === '') {
                        return self::fail('answer-note disagreement across seeds (tc ' . ($i + 1) . ', '
                            . $prtname . '): [' . implode(', ', $seen) . ']');
                    }
                    $q['tests'][$i]['expected'][$prtname]['answerNote'] = $seen[0];
                }
            }
        } finally {
            scratch_importer::delete_question($draftqid);
        }

        // 4. Build and import the final, notes-baked question into scratch.
        $finalxml = question_xml::build($q);
        $finalids = scratch_importer::import($finalxml, $scratchcat, $context, $course);
        if (count($finalids) !== 1) {
            foreach ($finalids as $id) {
                scratch_importer::delete_question((int) $id);
            }
            return self::fail('final import did not create exactly one question');
        }
        $finalqid = (int) $finalids[0];

        try {
            // 5. Authoritative: the exported question's own tests pass across every seed (Moodle's path).
            $tests = \question_bank::get_qtype('stack')->load_question_tests($finalqid);
            if (!$tests) {
                return self::fail('the final question exposed no question-tests');
            }
            foreach ($seeds as $seed) {
                foreach ($tests as $tc) {
                    try {
                        $result = $tc->test_question($finalqid, $seed, $context);
                    } catch (\Throwable $e) {
                        return self::fail('final test error (seed ' . $seed . '): ' . self::short($e->getMessage()));
                    }
                    if (!$result->passed()) {
                        return self::fail('final test failed (seed ' . $seed . ', tc ' . $tc->testcase . '): '
                            . self::short(self::reasons_summary($result->passed_with_reasons())));
                    }
                }
            }
        } finally {
            scratch_importer::delete_question($finalqid);
        }

        return [
            'ok' => true,
            'xml' => $finalxml,
            'reason' => '',
            'name' => $q['name'] ?? '',
            'type' => $q['type'] ?? '',
            'difficulty' => $q['difficulty'] ?? 'easy',
            'seeds' => count($seeds),
            'tests' => count($q['tests'] ?? []),
        ];
    }

    /**
     * The terminal node answer note STACK will compare against.
     *
     * STACK's stack_question_test_result::test_answer_note() pops the RAW last element of
     * get_answernotes() and compares it to the expected note. So we bake exactly that — the last
     * element — guaranteeing the match. If that last element is empty or an answer-test trace token
     * (ATAlgEquiv_true. ...) rather than a real terminal node note, the note is undiscoverable; we
     * return '' so the baking step rejects the question rather than committing a brittle note.
     *
     * @param array $answernotes The PRT result's get_answernotes() array (interleaved AT/node notes).
     * @return string The terminal node note, or '' if undiscoverable.
     */
    private static function canonical_note(array $answernotes): string {
        if (empty($answernotes)) {
            return '';
        }
        $last = trim((string) end($answernotes));
        if ($last === '' || preg_match('/^AT[A-Za-z0-9]/', $last)) {
            return '';
        }
        return $last;
    }

    /**
     * Summarise the failing PRT outcomes for a diagnostic message.
     *
     * @param array $reasons The output of stack_question_test_result::passed_with_reasons().
     * @return string A short human-readable summary.
     */
    private static function reasons_summary(array $reasons): string {
        $parts = [];
        foreach (($reasons['outcomes'] ?? []) as $prt => $o) {
            if (empty($o['outcome'])) {
                $parts[] = $prt . ' score=' . $o['score'] . '/exp=' . $o['expectedscore']
                    . ' note=' . $o['answernote'] . '/exp=' . $o['expectedanswernote'];
            }
        }
        if (!empty($reasons['reason'])) {
            $parts[] = $reasons['reason'];
        }
        return implode('; ', $parts);
    }

    /**
     * Build a failure result.
     *
     * @param string $reason The failure reason.
     * @return array ok=false with the reason.
     */
    private static function fail(string $reason): array {
        return ['ok' => false, 'xml' => null, 'reason' => $reason, 'seeds' => 0, 'tests' => 0];
    }

    /**
     * Trim a diagnostic string to a single short line.
     *
     * @param string $s The raw message.
     * @return string A single line, capped at 200 characters.
     */
    private static function short(string $s): string {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return strlen($s) > 200 ? substr($s, 0, 200) . '…' : $s;
    }
}
