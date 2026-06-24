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
 * Deterministic template for "simplify to lowest terms" questions.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local\templates;

/**
 * Two-node PRT: node 0 AlgEquiv (equivalent to the simplified answer?); node 1 checks the predicate
 * lowesttermsp(ans1) is true (LowestTerms only checks numeric coefficients, NOT rational-polynomial
 * cancellation - per the docs you must use lowesttermsp for that). Needs simp:false (questionSimplify
 * 0) so the input fraction is not auto-cancelled before the check.
 */
class simplify_lowest_terms extends base {
    /**
     * Build the structured question for this type.
     *
     * @param array $slot Optional difficulty/name/deployedSeeds.
     * @return array The structured question (consumed by question_xml::build).
     */
    public static function make(array $slot = []): array {
        $difficulty = $slot['difficulty'] ?? 'easy';

        $questionvariables = implode("\n", [
            'a : rand(5) + 2;',
            'expr : (x^2 - a^2)/(x - a);',
            'ta1 : fullratsimp(expr);', // Equals x + a.
        ]);

        return [
            'name' => $slot['name'] ?? "Simplify to lowest terms ({$difficulty})",
            'type' => 'simplify_lowest_terms',
            'difficulty' => $difficulty,
            'questionSimplify' => 0, // Keep the student's fraction uncancelled so we can detect it.
            'deployedSeeds' => $slot['deployedSeeds'] ?? [3, 17, 42, 101, 503],
            'questionVariables' => $questionvariables,
            'questionText' => "<p>Simplify \\( {@expr@} \\) to lowest terms.</p>\n<p>[[input:ans1]] [[validation:ans1]]</p>",
            'questionNote' => 'expr={@expr@}, simplified={@ta1@}',
            'generalFeedback' => "Cancel the common factor: \\( {@ta1@} \\).",
            'inputs' => [
                ['name' => 'ans1', 'type' => 'algebraic', 'tans' => 'ta1', 'boxSize' => 12],
            ],
            'prts' => [
                [
                    'name' => 'prt1',
                    'value' => 1,
                    'feedbackVariables' => 'lt : lowesttermsp(ans1);',
                    'nodes' => [
                        [
                            'name' => '0', 'answerTest' => 'AlgEquiv', 'sAns' => 'ans1', 'tAns' => 'ta1',
                            'trueScore' => 0, 'trueScoreMode' => '=', 'trueNextNode' => 1, 'trueAnswerNote' => 'prt1-1-T',
                            'falseScore' => 0, 'falseScoreMode' => '=', 'falsePenalty' => 0.1, 'falseNextNode' => -1,
                            'falseAnswerNote' => 'prt1-1-F',
                            'falseFeedback' => 'That is not equivalent to the original expression.',
                        ],
                        [
                            'name' => '1', 'answerTest' => 'AlgEquiv', 'sAns' => 'lt', 'tAns' => 'true',
                            'trueScore' => 1, 'trueScoreMode' => '=', 'trueNextNode' => -1, 'trueAnswerNote' => 'prt1-2-T',
                            'trueFeedback' => 'Correct.',
                            'falseScore' => 0, 'falseScoreMode' => '=', 'falsePenalty' => 0.1, 'falseNextNode' => -1,
                            'falseAnswerNote' => 'prt1-2-F',
                            'falseFeedback' => 'Equivalent, but not in lowest terms - cancel the common factor.',
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
