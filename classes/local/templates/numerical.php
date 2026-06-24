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
 * Deterministic template for "evaluate to a decimal" questions.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local\templates;

/**
 * Single PRT node, NumRelative (tolerance).
 */
class numerical extends base {
    public static function make(array $slot = []): array {
        $difficulty = $slot['difficulty'] ?? 'easy';

        $questionvariables = implode("\n", [
            'a : rand(80) + 20;',
            'b : rand(7) + 2;',
            'ta1 : float(a/b);',
        ]);

        return [
            'name' => $slot['name'] ?? "Evaluate to a decimal ({$difficulty})",
            'type' => 'numerical',
            'difficulty' => $difficulty,
            'deployedSeeds' => $slot['deployedSeeds'] ?? [3, 17, 42, 101, 503],
            'questionVariables' => $questionvariables,
            'questionText' => "<p>Evaluate \\( {@a@}/{@b@} \\) as a decimal (3 significant figures).</p>\n"
                . "<p>[[input:ans1]] [[validation:ans1]]</p>",
            'questionNote' => 'val={@a@}/{@b@}={@ta1@}',
            'generalFeedback' => "\\( {@a@}/{@b@} = {@ta1@} \\).",
            'inputs' => [
                ['name' => 'ans1', 'type' => 'numerical', 'tans' => 'ta1', 'boxSize' => 8, 'forbidFloat' => 0],
            ],
            'prts' => [
                [
                    'name' => 'prt1',
                    'value' => 1,
                    'nodes' => [
                        [
                            'answerTest' => 'NumRelative',
                            'sAns' => 'ans1',
                            'tAns' => 'ta1',
                            'testOptions' => '0.01',
                            'trueScore' => 1,
                            'trueFeedback' => 'Correct.',
                            'falseScore' => 0,
                            'falsePenalty' => 0.1,
                            'falseFeedback' => 'Not within tolerance - check your rounding.',
                        ],
                    ],
                ],
            ],
            'tests' => [
                ['inputs' => ['ans1' => 'ta1'], 'expected' => ['prt1' => ['score' => 1, 'answerNote' => 'prt1-1-T']]],
                ['inputs' => ['ans1' => 'ta1 + 10'], 'expected' => ['prt1' => ['score' => 0, 'penalty' => 0.1, 'answerNote' => 'prt1-1-F']]],
            ],
        ];
    }
}
