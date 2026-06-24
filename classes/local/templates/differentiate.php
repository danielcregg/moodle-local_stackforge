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
 * Deterministic template for "differentiate" questions.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local\templates;

/**
 * The AI's only job is to supply expr (the function to differentiate). The question's Maxima then
 * COMPUTES the teacher answer (ta1 : diff(expr, x)), so the answer is correct by construction.
 */
class differentiate extends base {
    /** @var string[] Safe fallback expressions (used when the AI supplies none). */
    const DEFAULT_EXPRS = ['(x-a)^3', 'a*x^3 - x', 'x^4 + a*x^2', '(x+a)^3 - x'];

    /**
     * The fallback expressions for this expr-driven type.
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
        $expr = $slot['expr'] ?? self::DEFAULT_EXPRS[0];
        $difficulty = $slot['difficulty'] ?? 'easy';

        // Randomise a in 2..6 to avoid trivial/degenerate variants.
        $questionvariables = implode("\n", [
            'a : rand(5) + 2;',
            "expr : {$expr};",
            'ta1 : diff(expr, x);',
        ]);

        return [
            'name' => $slot['name'] ?? "Differentiate ({$difficulty})",
            'type' => 'differentiate',
            'difficulty' => $difficulty,
            'deployedSeeds' => $slot['deployedSeeds'] ?? [3, 17, 42, 101, 503],
            'questionVariables' => $questionvariables,
            'questionText' => "<p>Differentiate \\( {@expr@} \\) with respect to \\(x\\).</p>\n"
                . "<p>[[input:ans1]] [[validation:ans1]]</p>",
            'questionNote' => 'expr={@expr@}, ans={@ta1@}',
            'generalFeedback' => "Differentiate term by term; the derivative is \\( {@ta1@} \\).",
            'inputs' => [
                ['name' => 'ans1', 'type' => 'algebraic', 'tans' => 'ta1', 'boxSize' => 16],
            ],
            'prts' => [
                [
                    'name' => 'prt1',
                    'value' => 1,
                    'nodes' => [
                        [
                            'answerTest' => 'AlgEquiv',
                            'sAns' => 'ans1',
                            'tAns' => 'ta1',
                            'trueScore' => 1,
                            'trueFeedback' => 'Correct.',
                            'falseScore' => 0,
                            'falsePenalty' => 0.1,
                            'falseFeedback' => 'That is not the derivative — differentiate term by term.',
                        ],
                    ],
                ],
            ],
            'tests' => [
                ['inputs' => ['ans1' => 'ta1'], 'expected' => ['prt1' => ['score' => 1, 'answerNote' => 'prt1-1-T']]],
                [
                    'inputs' => ['ans1' => 'ta1 + 1'],
                    'expected' => ['prt1' => ['score' => 0, 'penalty' => 0.1, 'answerNote' => 'prt1-1-F']],
                ],
            ],
        ];
    }
}
