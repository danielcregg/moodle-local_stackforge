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
 * Deterministic template for "solve a linear equation" questions.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local\templates;

/**
 * Single PRT node, AlgEquiv. Maxima solves the equation, so the model answer is correct by
 * construction.
 */
class solve_linear extends base {
    /**
     * Build the structured question for this type.
     *
     * @param array $slot Optional difficulty/name/deployedSeeds.
     * @return array The structured question (consumed by question_xml::build).
     */
    public static function make(array $slot = []): array {
        $difficulty = $slot['difficulty'] ?? 'easy';

        $questionvariables = implode("\n", [
            'b : rand(4) + 2;', // Nonzero coefficient.
            'c : rand(11) - 5;',
            'd : rand(11) - 5;',
            'eq : b*x + c = d;',
            'ta1 : rhs(solve(eq, x)[1]);',
        ]);

        return [
            'name' => $slot['name'] ?? "Solve a linear equation ({$difficulty})",
            'type' => 'solve_linear',
            'difficulty' => $difficulty,
            'deployedSeeds' => $slot['deployedSeeds'] ?? [3, 17, 42, 101, 503],
            'questionVariables' => $questionvariables,
            'questionText' => "<p>Solve \\( {@eq@} \\) for \\(x\\).</p>\n"
                . "<p>\\(x=\\) [[input:ans1]] [[validation:ans1]]</p>",
            'questionNote' => 'eq={@eq@}, x={@ta1@}',
            'generalFeedback' => "Rearrange to isolate \\(x\\): \\(x = {@ta1@}\\).",
            'inputs' => [
                ['name' => 'ans1', 'type' => 'algebraic', 'tans' => 'ta1', 'boxSize' => 8],
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
                            'falseFeedback' => "Not correct - isolate \\(x\\).",
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
