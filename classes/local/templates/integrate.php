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
 * Deterministic template for "find an antiderivative" questions.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local\templates;

/**
 * Single PRT node, Antidiff (allows +C). Antidiff(ans1, expr) passes iff d/dx(ans1) = expr, so any
 * valid antiderivative (differing by a constant) is accepted. The student is forbidden int().
 */
class integrate extends base {
    /** @var string[] Safe fallback integrands (all polynomials -> elementary antiderivatives). */
    const DEFAULT_EXPRS = ['a*x^2 + x', 'x^3 - a*x', 'a*x^3 + x^2', '(x + a)^2'];

    /**
     * The fallback integrands for this expr-driven type.
     *
     * @return string[] The default expressions.
     */
    public static function default_exprs(): array {
        return self::DEFAULT_EXPRS;
    }

    /**
     * Build the structured question for this type.
     *
     * @param array $slot Optional expr/difficulty/name/deployedSeeds.
     * @return array The structured question (consumed by question_xml::build).
     */
    public static function make(array $slot = []): array {
        $difficulty = $slot['difficulty'] ?? 'easy';
        $expr = $slot['expr'] ?? self::DEFAULT_EXPRS[0];

        // Oracle guard: a non-elementary integrand (e.g. sqrt(x^3+1), which passes the grammar)
        // leaves an unevaluated 'integrate noun. The student cannot type that (int() is forbidden),
        // so the question is ungradeable - yet Antidiff(ta1,ta1) would self-match and "pass". On noun
        // detection we force a 1/0 Division-by-zero: the question fails to instantiate and validation
        // rejects it. (error() is a forbidden STACK function; 1/0 is lazy in if-else.)
        $questionvariables = implode("\n", [
            'a : rand(4) + 2;',
            "expr : {$expr};",
            'ta1 : integrate(expr, x);',
            'ta1 : if freeof(nounify(integrate), ta1) then ta1 else 1/0;',
        ]);

        return [
            'name' => $slot['name'] ?? "Find an antiderivative ({$difficulty})",
            'type' => 'integrate',
            'difficulty' => $difficulty,
            'deployedSeeds' => $slot['deployedSeeds'] ?? [3, 17, 42, 101, 503],
            'questionVariables' => $questionvariables,
            'questionText' => "<p>Find an antiderivative of \\( {@expr@} \\) with respect to \\(x\\) "
                . "(no constant of integration needed).</p>\n<p>[[input:ans1]] [[validation:ans1]]</p>",
            'questionNote' => 'expr={@expr@}, F={@ta1@}',
            'generalFeedback' => "Integrate term by term: \\( {@ta1@} (+C) \\).",
            'inputs' => [
                ['name' => 'ans1', 'type' => 'algebraic', 'tans' => 'ta1', 'boxSize' => 16, 'forbidWords' => 'int,integrate'],
            ],
            'prts' => [
                [
                    'name' => 'prt1',
                    'value' => 1,
                    'nodes' => [
                        [
                            'answerTest' => 'Antidiff',
                            'sAns' => 'ans1',
                            'tAns' => 'ta1',
                            'testOptions' => 'x',
                            'trueScore' => 1,
                            'trueFeedback' => 'Correct.',
                            'falseScore' => 0,
                            'falsePenalty' => 0.1,
                            'falseFeedback' => 'Differentiating your answer should give the integrand.',
                        ],
                    ],
                ],
            ],
            'tests' => [
                ['inputs' => ['ans1' => 'ta1'], 'expected' => ['prt1' => ['score' => 1, 'answerNote' => 'prt1-1-T']]],
                [
                    'inputs' => ['ans1' => 'ta1 + x'],
                    'expected' => ['prt1' => ['score' => 0, 'penalty' => 0.1, 'answerNote' => 'prt1-1-F']],
                ],
            ],
        ];
    }
}
