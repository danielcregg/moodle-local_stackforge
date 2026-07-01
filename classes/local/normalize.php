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
 * Safe tidy-ups and the allow-list grammar gate for AI-supplied expressions.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * Mirrors docs/js/normalize.js: a meaning-preserving tidy plus the allow-list grammar gate.
 *
 * The gate is an ALLOW-LIST, not a deny-list: a deny-list is structurally unsafe because Maxima
 * has too many I/O primitives (load, batch, read, save, ?lisp-escapes, statement separators ; $).
 * Only well-formed single arithmetic expressions over the allowed identifiers pass.
 */
class normalize {
    /**
     * Identifiers an AI-supplied expression is allowed to mention: the variable x, the integer
     * parameters a/b the templates randomise, and a small set of safe CAS functions.
     *
     * @var string[]
     */
    const ALLOWED_IDENTS = ['x', 'a', 'b', 'expand', 'factor', 'sqrt'];

    /**
     * Safe, meaning-preserving tidy-up of a slot field. Never changes the mathematics.
     *
     * @param mixed $expr The raw AI-supplied expression.
     * @return string The trimmed, whitespace-collapsed expression with trailing terminators removed.
     */
    public static function tidy_expr($expr): string {
        if ($expr === null) {
            return '';
        }
        $s = trim((string) $expr);
        $s = preg_replace('/\s+/', ' ', $s);              // Collapse whitespace.
        $s = preg_replace('/[\s;$]+$/', '', $s);          // A slot is an expression, not a statement.
        return $s;
    }

    /**
     * Gate an AI expression before it is interpolated into Maxima source (expr : <expr>;).
     *
     * @param mixed $expr The candidate expression.
     * @return bool True only for a well-formed arithmetic expression over the allowed identifiers.
     */
    public static function looks_safe_expr($expr): bool {
        $s = trim((string) ($expr ?? ''));
        if ($s === '' || strlen($s) > 200) {
            return false;
        }
        // Whitelist the ENTIRE character set: digits, letters/underscore (identifiers, checked
        // below), arithmetic operators, parentheses, comma, decimal point and whitespace. This
        // alone rejects ; $ : " ' ? \ and every other statement/IO character.
        if (!preg_match('~^[0-9A-Za-z_+\-*/^(),.\s]+$~', $s)) {
            return false;
        }
        // Every identifier must be on the allow-list (blocks load/batch/read/system/...).
        if (preg_match_all('/[A-Za-z_]\w*/', $s, $matches)) {
            foreach ($matches[0] as $id) {
                if (!in_array($id, self::ALLOWED_IDENTS, true)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Extract the first JSON object from a model response.
     *
     * Strips reasoning-model &lt;think&gt; blocks and markdown code fences, then scans for the first
     * balanced {...} run (depth-tracked, quote-aware) and removes trailing commas before decoding. This
     * hardens the plain first-brace / last-brace approach against prose that contains stray braces.
     *
     * @param string $text The raw model output.
     * @return array|null The decoded object, or null if none could be parsed.
     */
    public static function extract_json(string $text): ?array {
        $s = (string) $text;
        // Drop any reasoning-model thinking block and its content.
        $s = preg_replace('~<think>.*?</think>~is', '', $s);
        // Drop markdown code-fence markers but keep the fenced content (the fence is three backticks).
        $fence = str_repeat(chr(96), 3);
        $s = preg_replace('~' . $fence . '[a-zA-Z0-9]*~', '', $s);
        $s = str_replace($fence, '', $s);

        $len = strlen($s);
        $start = strpos($s, '{');
        while ($start !== false) {
            $depth = 0;
            $instring = false;
            $escaped = false;
            for ($i = $start; $i < $len; $i++) {
                $ch = $s[$i];
                if ($instring) {
                    if ($escaped) {
                        $escaped = false;
                    } else if ($ch === '\\') {
                        $escaped = true;
                    } else if ($ch === '"') {
                        $instring = false;
                    }
                    continue;
                }
                if ($ch === '"') {
                    $instring = true;
                } else if ($ch === '{') {
                    $depth++;
                } else if ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $candidate = substr($s, $start, $i - $start + 1);
                        // Remove trailing commas before a closing brace or bracket.
                        $candidate = preg_replace('/,\s*([}\]])/', '$1', $candidate);
                        $data = json_decode($candidate, true);
                        if (is_array($data)) {
                            return $data;
                        }
                        break;
                    }
                }
            }
            $start = strpos($s, '{', $start + 1);
        }
        return null;
    }

    /**
     * Deterministically repair a raw AI-supplied expression before the allow-list gate.
     *
     * This never replaces the gate; it only removes the common small-model surface noise (LaTeX
     * delimiters and commands, markdown, ** for powers, unbalanced parentheses, missing implicit
     * multiplication) so a mathematically fine expression is not rejected on formatting alone. A hostile
     * expression cannot be made safe by these fixes, so looks_safe_expr() still decides admissibility.
     *
     * @param string $raw The raw AI-supplied expression.
     * @return array A two-element list: the repaired expression (string) and the fixes applied (string[]).
     */
    public static function repair_expr(string $raw): array {
        $fixes = [];
        // Trim and collapse whitespace, but do NOT strip trailing terminators yet: a $...$ wrapper must
        // be recognised as a pair before tidy_expr() would remove the closing dollar sign.
        $s = trim((string) $raw);
        $s = preg_replace('/\s+/', ' ', $s);
        if ($s === '') {
            return ['', $fixes];
        }

        // Strip a surrounding LaTeX inline or display wrapper from the whole expression.
        $before = $s;
        $s = preg_replace('/^\\\\[\(\[]\s*(.*?)\s*\\\\[\)\]]$/', '$1', $s);
        $s = preg_replace('/^\$+\s*(.*?)\s*\$+$/', '$1', $s);
        if ($s !== $before) {
            $fixes[] = 'stripped LaTeX delimiters';
        }

        // Strip markdown backtick characters.
        $tick = chr(96);
        if (strpos($s, $tick) !== false) {
            $s = str_replace($tick, '', $s);
            $fixes[] = 'stripped backticks';
        }

        // Normalise a doubled-asterisk power operator to a caret.
        if (strpos($s, '**') !== false) {
            $s = str_replace('**', '^', $s);
            $fixes[] = 'normalised power operator';
        }

        // Convert the common LaTeX multiplication and grouping commands, then drop any other backslash
        // command so the allow-list is judging the underlying arithmetic, not the markup.
        $before = $s;
        $s = str_replace(['\\cdot', '\\times', '\\left', '\\right'], ['*', '*', '(', ')'], $s);
        $s = preg_replace('/\\\\[a-zA-Z]+/', '', $s);
        $s = str_replace('\\', '', $s);
        if ($s !== $before) {
            $fixes[] = 'converted LaTeX commands';
        }

        // Insert implicit multiplication a small model tends to omit. A letter immediately before an
        // opening parenthesis is left alone so a function name is not broken.
        $before = $s;
        // Between a digit and a following letter.
        $s = preg_replace('/(\d)([A-Za-z])/', '$1*$2', $s);
        // Between a digit and an opening parenthesis.
        $s = preg_replace('/(\d)\(/', '$1*(', $s);
        // Between a closing parenthesis and a following letter or digit.
        $s = preg_replace('/\)([0-9A-Za-z])/', ')*$1', $s);
        // Between two adjacent parenthesis groups.
        $s = preg_replace('/\)\(/', ')*(', $s);
        if ($s !== $before) {
            $fixes[] = 'inserted implicit multiplication';
        }

        // Balance parentheses: append any missing close, or drop stray trailing closes.
        $open = substr_count($s, '(');
        $close = substr_count($s, ')');
        if ($open > $close) {
            $s .= str_repeat(')', $open - $close);
            $fixes[] = 'balanced parentheses';
        } else if ($close > $open) {
            // Remove exactly the excess trailing closes, not an entire trailing run.
            $s = preg_replace('/\){1,' . ($close - $open) . '}$/', '', $s, 1);
            $s = self::tidy_expr($s);
            $fixes[] = 'trimmed stray parentheses';
        }

        // A final tidy in case the substitutions left doubled spaces or a trailing operator.
        $s = self::tidy_expr($s);
        $s = preg_replace('/[+\-*\/^]+$/', '', $s);
        $s = self::tidy_expr($s);

        return [$s, $fixes];
    }
}
