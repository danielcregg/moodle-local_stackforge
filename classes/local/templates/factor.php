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
 * Deterministic template for "factorise" questions.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local\templates;

/**
 * Single-node FacForm: checks BOTH that the answer is equivalent to ta1 AND that it is factored
 * over the rationals. The variable goes in the test's Options field; the Teacher Answer is ta1.
 */
class factor extends base {
    /**
     * Build the structured question for this type.
     *
     * @param array $slot Optional difficulty/name/deployedSeeds.
     * @return array The structured question (consumed by question_xml::build).
     */
    public static function make(array $slot = []): array {
        $difficulty = $slot['difficulty'] ?? 'easy';

        $questionvariables = implode("\n", [
            'a : rand(4) + 1;',
            'b : rand(4) + 1;',
            'expr : expand((x + a)*(x + b));',
            'ta1 : factor(expr);',
        ]);

        return [
            'name' => $slot['name'] ?? "Factorise ({$difficulty})",
            'type' => 'factor',
            'difficulty' => $difficulty,
            'deployedSeeds' => $slot['deployedSeeds'] ?? [3, 17, 42, 101, 503],
            'questionVariables' => $questionvariables,
            'questionText' => "<p>Factorise \\( {@expr@} \\).</p>\n<p>[[input:ans1]] [[validation:ans1]]</p>",
            'questionNote' => 'expr={@expr@}, factored={@ta1@}',
            'generalFeedback' => "Factorise into linear factors: \\( {@ta1@} \\).",
            'inputs' => [
                ['name' => 'ans1', 'type' => 'algebraic', 'tans' => 'ta1', 'boxSize' => 16],
            ],
            'prts' => [
                [
                    'name' => 'prt1',
                    'value' => 1,
                    'nodes' => [
                        [
                            'answerTest' => 'FacForm',
                            'sAns' => 'ans1',
                            'tAns' => 'ta1',
                            'testOptions' => 'x',
                            'trueScore' => 1,
                            'trueFeedback' => 'Correct.',
                            'falseScore' => 0,
                            'falsePenalty' => 0.1,
                            'falseFeedback' => 'Not fully factorised (or not equivalent to the original).',
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
