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
 * Tests for the deterministic pre-CAS expression repair.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * repair_expr() only removes small-model surface noise before the unchanged allow-list gate; it must
 * never turn a hostile string into a safe one.
 *
 * @covers \local_stackforge\local\normalize
 */
final class repair_expr_test extends \advanced_testcase {
    /**
     * LaTeX delimiters, markdown backticks and ** powers are stripped or normalised.
     *
     * @return void
     */
    public function test_strips_markup(): void {
        $tick = chr(96);
        $this->assertSame('a*x^3', normalize::repair_expr('$a*x^3$')[0]);
        $this->assertSame('2*x + a', normalize::repair_expr('\(2x + a\)')[0]);
        $this->assertSame('a*x^2 + x', normalize::repair_expr('a*x**2 + x')[0]);
        $this->assertSame('a*x^2', normalize::repair_expr($tick . 'a*x^2' . $tick)[0]);
    }

    /**
     * Implicit multiplication a small model omits is inserted, without breaking a function call.
     *
     * @return void
     */
    public function test_inserts_implicit_multiplication(): void {
        $this->assertSame('2*x', normalize::repair_expr('2x')[0]);
        $this->assertSame('3*x^2 + 2*x', normalize::repair_expr('3x^2 + 2x')[0]);
        // A function call name immediately before "(" is left intact.
        $this->assertSame('expand((x+a)*(x+b))', normalize::repair_expr('expand((x+a)*(x+b))')[0]);
    }

    /**
     * Unbalanced parentheses are balanced (missing close appended, excess trailing close trimmed).
     *
     * @return void
     */
    public function test_balances_parentheses(): void {
        $this->assertSame('a*(x-a)', normalize::repair_expr('a*(x-a')[0]);
        $this->assertSame('(x-a)^3 - x', normalize::repair_expr('(x-a)^3 - x)')[0]);
        $this->assertSame('sqrt(x^3+1)', normalize::repair_expr('sqrt(x^3+1))')[0]);
    }

    /**
     * An unchanged, clean expression reports no fixes; a repaired one reports what it did.
     *
     * @return void
     */
    public function test_reports_fixes(): void {
        [$expr, $fixes] = normalize::repair_expr('a*x^2 + x');
        $this->assertSame('a*x^2 + x', $expr);
        $this->assertSame([], $fixes);

        [, $fixes2] = normalize::repair_expr('$2x$');
        $this->assertNotEmpty($fixes2);
    }

    /**
     * A repaired benign expression still passes the allow-list gate.
     *
     * @return void
     */
    public function test_repaired_benign_passes_gate(): void {
        foreach (['2x', '$a*x^3$', '\(2x + a\)', 'a*x**2 + x', 'sqrt(x^3+1))'] as $raw) {
            [$expr] = normalize::repair_expr($raw);
            $this->assertTrue(normalize::looks_safe_expr($expr), "expected repaired-safe: $raw -> $expr");
        }
    }

    /**
     * Repair must NOT make a hostile string admissible — the gate still rejects it.
     *
     * @return void
     */
    public function test_hostile_still_blocked_after_repair(): void {
        $hostile = ['system("rm")', 'x; load(y)', 'kill(all)', 'a:5', '?lisp', 'batch("f")', 'sin(x)'];
        foreach ($hostile as $raw) {
            [$expr] = normalize::repair_expr($raw);
            $this->assertFalse(normalize::looks_safe_expr($expr), "expected still-blocked: $raw -> $expr");
        }
    }
}
