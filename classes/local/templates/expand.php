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
 * Deterministic template for "expand" questions.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local\templates;

/**
 * Two-node PRT: first AlgEquiv (equivalent to the answer?), then Expanded (in expanded form?).
 * This gives distinct feedback for "not equivalent" vs "equivalent but not expanded".
 */
class expand extends base {
    public static function make(array $slot = []): array {
        $difficulty = $slot['difficulty'] ?? 'easy';

        $questionvariables = implode("\n", [
            'a : rand(5) + 1;',
            'b : rand(5) + 1;',
            'expr : (x + a)*(x + b);',
            'ta1 : expand(expr);',
        ]);

        return [
            'name' => $slot['name'] ?? "Expand ({$difficulty})",
            'type' => 'expand',
            'difficulty' => $difficulty,
            'deployedSeeds' => $slot['deployedSeeds'] ?? [3, 17, 42, 101, 503],
            'questionVariables' => $questionvariables,
            'questionText' => "<p>Expand \\( {@expr@} \\).</p>\n<p>[[input:ans1]] [[validation:ans1]]</p>",
            'questionNote' => 'expr={@expr@}, expanded={@ta1@}',
            'generalFeedback' => "Multiply out and collect like terms: \\( {@ta1@} \\).",
            'inputs' => [
                ['name' => 'ans1', 'type' => 'algebraic', 'tans' => 'ta1', 'boxSize' => 16],
            ],
            'prts' => [
                [
                    'name' => 'prt1',
                    'value' => 1,
                    'nodes' => [
                        [
                            // Node 0: equivalent to the expanded answer?
                            'name' => '0', 'answerTest' => 'AlgEquiv', 'sAns' => 'ans1', 'tAns' => 'ta1',
                            'trueScore' => 0, 'trueScoreMode' => '=', 'trueNextNode' => 1, 'trueAnswerNote' => 'prt1-1-T',
                            'falseScore' => 0, 'falseScoreMode' => '=', 'falsePenalty' => 0.1, 'falseNextNode' => -1,
                            'falseAnswerNote' => 'prt1-1-F',
                            'falseFeedback' => 'That is not equivalent to the original expression.',
                        ],
                        [
                            // Node 1: written in expanded form?
                            'name' => '1', 'answerTest' => 'Expanded', 'sAns' => 'ans1', 'tAns' => 'ta1',
                            'trueScore' => 1, 'trueScoreMode' => '=', 'trueNextNode' => -1, 'trueAnswerNote' => 'prt1-2-T',
                            'trueFeedback' => 'Correct.',
                            'falseScore' => 0, 'falseScoreMode' => '=', 'falsePenalty' => 0.1, 'falseNextNode' => -1,
                            'falseAnswerNote' => 'prt1-2-F',
                            'falseFeedback' => 'Equivalent, but not fully expanded - multiply out all brackets.',
                        ],
                    ],
                ],
            ],
            'tests' => [
                ['inputs' => ['ans1' => 'ta1'], 'expected' => ['prt1' => ['score' => 1]]],
                ['inputs' => ['ans1' => 'ta1 + 1'], 'expected' => ['prt1' => ['score' => 0, 'penalty' => 0.1]]],
            ],
        ];
    }
}
