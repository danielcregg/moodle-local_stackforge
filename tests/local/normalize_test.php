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
 * Tests for the allow-list safe-expression gate.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * The gate is security-critical: it is the only thing standing between an AI-proposed string and
 * Maxima source interpolation (expr : &lt;expr&gt;;).
 *
 * @covers \local_stackforge\local\normalize
 */
final class normalize_test extends \advanced_testcase {
    /**
     * tidy_expr trims, collapses whitespace, and drops trailing statement terminators.
     *
     * @return void
     */
    public function test_tidy_expr(): void {
        $this->assertSame('a*x^2 + x', normalize::tidy_expr("  a*x^2 + x ;  "));
        $this->assertSame('x^2', normalize::tidy_expr("x^2\n"));
        $this->assertSame('', normalize::tidy_expr(null));
    }

    /**
     * Well-formed arithmetic expressions over the allowed identifiers pass.
     *
     * @return void
     */
    public function test_safe_expressions_pass(): void {
        $safe = ['(x-a)^3', 'a*x^3 - x', 'x^4 + a*x^2', 'sqrt(x^3+1)',
            'expand((x+a)*(x+b))', 'factor(x^2 - a^2)', 'b*x + a', '(x + a)^2'];
        foreach ($safe as $expr) {
            $this->assertTrue(normalize::looks_safe_expr($expr), "expected safe: $expr");
        }
    }

    /**
     * Anything with an I/O primitive, statement separator, disallowed identifier, or odd character
     * is rejected.
     *
     * @return void
     */
    public function test_unsafe_expressions_blocked(): void {
        $unsafe = ['system("rm")', 'load("x")', 'x; load(y)', 'x$ y', 'a:5', '?lisp', 'sin(x)',
            'f(x):=x', "x'2", 'x"y"', 'kill(all)', 'x | y', 'batch("f")', '', str_repeat('x', 201)];
        foreach ($unsafe as $expr) {
            $this->assertFalse(normalize::looks_safe_expr($expr), "expected blocked: $expr");
        }
    }

    /**
     * extract_json recovers the first balanced object, ignoring think blocks, code fences and prose,
     * and tolerates a trailing comma.
     *
     * @return void
     */
    public function test_extract_json(): void {
        $fence = str_repeat(chr(96), 3);
        $this->assertSame(['expr' => 'a*x^2'], normalize::extract_json('{"expr": "a*x^2"}'));
        $this->assertSame(
            ['expr' => 'a*x^2'],
            normalize::extract_json($fence . "json\n{\"expr\": \"a*x^2\"}\n" . $fence)
        );
        $this->assertSame(
            ['expr' => 'x^3'],
            normalize::extract_json('<think>maybe {ignore}</think> Here it is: {"expr": "x^3"}')
        );
        // A trailing comma before the closing brace is tolerated.
        $this->assertSame(['expr' => 'x'], normalize::extract_json('prose {"expr": "x",}'));
        $this->assertNull(normalize::extract_json('no json here'));
        $this->assertNull(normalize::extract_json(''));
    }
}
