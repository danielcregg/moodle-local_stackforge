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
}
