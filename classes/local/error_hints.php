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
 * Maps an oracle failure reason to a short natural-language retry hint.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * The POST-stage retry catalog. inprocess_validator returns a diagnostic 'reason' string when a proposed
 * expression fails (a runtime CAS error, an answer-note disagreement, a bulk-validation failure, and so
 * on). This class turns that reason into one short, non-technical instruction that is fed into the next
 * attempt's AVOID block, so a small model steers away from the same mistake. It is pure and unit-testable;
 * an unrecognised reason yields a safe generic hint (never empty).
 */
class error_hints {
    /**
     * A short retry hint for the next attempt, derived from an oracle failure reason.
     *
     * @param string $reason The failure reason from inprocess_validator::validate().
     * @param string $type The question type (lets the catalog give type-specific guidance).
     * @return string A one-line instruction to add to the next attempt's AVOID block (never empty).
     */
    public static function hint_for(string $reason, string $type): string {
        $r = \core_text::strtolower(trim($reason));

        // Noun-form / division-by-zero: the integrand had no elementary antiderivative (or a term blew up).
        // The integrate template forces 1/0 on an unevaluated integrate noun, so this is the common miss.
        if (strpos($r, 'division by zero') !== false || strpos($r, 'noun') !== false) {
            if ($type === 'integrate') {
                return 'Choose an integrand whose antiderivative is elementary: use a plain polynomial in x '
                    . 'such as a*x^2 + x. Never use a square root, a fraction, or a transcendental function.';
            }
            return 'Avoid expressions that are undefined or divide by zero; use a plain polynomial in x.';
        }

        // Any other runtime / instantiation CAS error while evaluating the question variables.
        if (strpos($r, 'runtime cas error') !== false || strpos($r, 'instantiation error') !== false) {
            return 'The expression caused a CAS error. Keep it a simple polynomial in x with small integer '
                . 'coefficients and the parameter a.';
        }

        // The answer note differed across random seeds: the derived answer was not a single clean form.
        if (strpos($r, 'answer-note disagreement') !== false || strpos($r, 'answer note') !== false) {
            return 'Pick an expression whose derivative or antiderivative is a single clean polynomial for '
                . 'every value of a. Avoid absolute values, roots and piecewise behaviour.';
        }

        // STACK could not validate the expression for bulk use (a malformed or unsupported expression).
        if (strpos($r, 'validate_for_bulk') !== false || strpos($r, 'stackversion') !== false) {
            return 'Write a valid single Maxima expression using only x, the integer parameter a, and the '
                . 'operators + - * ^.';
        }

        // The grading tree threw while testing the model answer.
        if (strpos($r, 'prt runtime error') !== false) {
            return 'The grading step failed on your expression. Keep it a straightforward polynomial in x so '
                . 'its derivative or antiderivative grades cleanly.';
        }

        // The final authoritative question-test did not pass across every seed.
        if (strpos($r, 'final test failed') !== false || strpos($r, 'final test error') !== false) {
            return 'The generated question did not pass its own tests. Use a simpler polynomial in x whose '
                . 'answer is unambiguous for every value of a.';
        }

        // An unsafe or unparseable expression was rejected by the allow-list gate.
        if (strpos($r, 'unsafe') !== false || strpos($r, 'gate') !== false || strpos($r, 'identifier') !== false) {
            return 'Use only the variable x and the integer parameters a and b, with the operators + - * ^. '
                . 'Do not call any function other than expand, factor or sqrt.';
        }

        // Anything else: a safe, generic nudge toward a simpler expression.
        return 'The previous expression failed validation. Try a simpler polynomial in x using the integer '
            . 'parameter a.';
    }
}
