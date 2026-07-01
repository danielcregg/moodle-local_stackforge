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
 * Tests for the backend-agnostic prompt builder.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * The single PRE builder both the server client and the on-device browser consume; it is pure, so it is
 * fully exercised here without Maxima.
 *
 * @covers \local_stackforge\local\prompt_rules
 */
final class prompt_rules_test extends \advanced_testcase {
    /**
     * Only the two expr-driven types produce a prompt; the other six are template-only.
     *
     * @return void
     */
    public function test_only_expr_types_are_supported(): void {
        $this->assertTrue(prompt_rules::supports('differentiate'));
        $this->assertTrue(prompt_rules::supports('integrate'));
        foreach (['expand', 'factor', 'simplify_lowest_terms', 'solve_linear', 'solve_quadratic', 'numerical'] as $t) {
            $this->assertFalse(prompt_rules::supports($t), "should be template-only: $t");
            $this->assertSame(['system' => '', 'user' => ''], prompt_rules::messages($t, 'easy'));
        }
    }

    /**
     * The compact and verbose tiers share the minimal-JSON contract; only verbose adds RIGHT/WRONG
     * examples. Both carry a non-empty system and user message naming the JSON contract and the type.
     *
     * @return void
     */
    public function test_compact_and_verbose_shape(): void {
        $compact = prompt_rules::compact('differentiate', 'easy');
        $verbose = prompt_rules::verbose('differentiate', 'easy');

        foreach ([$compact, $verbose] as $m) {
            $this->assertArrayHasKey('system', $m);
            $this->assertArrayHasKey('user', $m);
            $this->assertNotEmpty($m['system']);
            $this->assertNotEmpty($m['user']);
            $this->assertStringContainsString('{"expr"', $m['system']);
            $this->assertStringContainsString('differentiate', $m['user']);
        }

        // Only the verbose tier ships the RIGHT / WRONG contrast examples.
        $this->assertStringNotContainsString('WRONG', $compact['system']);
        $this->assertStringContainsString('WRONG', $verbose['system']);
        $this->assertStringContainsString('RIGHT', $verbose['system']);
    }

    /**
     * The system prompt carries one type-matched few-shot example; unsupported types get none.
     *
     * @return void
     */
    public function test_fewshot_is_present_and_type_matched(): void {
        $this->assertStringContainsString('a*x^3 - x', prompt_rules::fewshot('differentiate'));
        $this->assertStringContainsString('a*x^2 + x', prompt_rules::fewshot('integrate'));
        $this->assertStringContainsString('"expr"', prompt_rules::fewshot('differentiate'));
        $this->assertSame('', prompt_rules::fewshot('expand'));

        // The compact system prompt embeds the few-shot example.
        $compact = prompt_rules::compact('integrate', 'easy');
        $this->assertStringContainsString('a*x^2 + x', $compact['system']);
    }

    /**
     * A supplied AVOID list (recent Maxima failures) is injected into the user prompt.
     *
     * @return void
     */
    public function test_avoid_block_is_injected(): void {
        $with = prompt_rules::messages('integrate', 'hard', ['choose an elementary integrand', 'no square roots']);
        $this->assertStringContainsString('Avoid what made earlier attempts fail', $with['user']);
        $this->assertStringContainsString('choose an elementary integrand', $with['user']);
        $this->assertStringContainsString('no square roots', $with['user']);

        // With no AVOID list, that block is absent.
        $without = prompt_rules::messages('integrate', 'hard');
        $this->assertStringNotContainsString('Avoid what made earlier attempts fail', $without['user']);
    }

    /**
     * A supplied used-expression list becomes an anti-duplication instruction.
     *
     * @return void
     */
    public function test_used_list_becomes_anti_duplication(): void {
        $m = prompt_rules::messages('differentiate', 'easy', [], ['a*x^2 + x', '(x-a)^3']);
        $this->assertStringContainsString('Do not reuse', $m['user']);
        $this->assertStringContainsString('a*x^2 + x', $m['user']);
        $this->assertStringContainsString('(x-a)^3', $m['user']);
    }

    /**
     * Difficulty changes the shaping guidance in the user prompt.
     *
     * @return void
     */
    public function test_difficulty_changes_guidance(): void {
        $easy = prompt_rules::messages('differentiate', 'easy')['user'];
        $hard = prompt_rules::messages('differentiate', 'hard')['user'];
        $this->assertNotSame($easy, $hard);
        $this->assertStringContainsString('easy', $easy);
        $this->assertStringContainsString('hard', $hard);
    }
}
