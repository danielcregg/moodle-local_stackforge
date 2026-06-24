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
 * Deterministic template for "solve a quadratic" questions.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local\templates;

/**
 * Single-node AlgEquiv on a SET of roots. Distinct real integer roots only (r2 is offset from r1
 * so they never coincide). The roots are computed via solve (the oracle), not by assumption.
 */
class solve_quadratic extends base {
    public static function make(array $slot = []): array {
        $difficulty = $slot['difficulty'] ?? 'easy';

        $questionvariables = implode("\n", [
            'r1 : rand(5) + 1;',
            'r2 : r1 + rand(4) + 1;',          // Distinct from r1.
            'eq : expand((x - r1)*(x - r2)) = 0;',
            'ta1 : setify(map(rhs, solve(eq, x)));',
        ]);

        return [
            'name' => $slot['name'] ?? "Solve a quadratic ({$difficulty})",
            'type' => 'solve_quadratic',
            'difficulty' => $difficulty,
            'deployedSeeds' => $slot['deployedSeeds'] ?? [3, 17, 42, 101, 503],
            'questionVariables' => $questionvariables,
            'questionText' => "<p>Solve \\( {@eq@} \\) for \\(x\\). Enter the solutions as a set, "
                . "e.g. <code>{1,2}</code>.</p>\n<p>[[input:ans1]] [[validation:ans1]]</p>",
            'questionNote' => 'eq={@eq@}, roots={@ta1@}',
            'generalFeedback' => "Factorise the quadratic; the roots are \\( {@ta1@} \\).",
            'inputs' => [
                ['name' => 'ans1', 'type' => 'algebraic', 'tans' => 'ta1', 'boxSize' => 12, 'syntaxHint' => '{?,?}'],
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
                            'falseFeedback' => 'Not the complete set of roots.',
                        ],
                    ],
                ],
            ],
            'tests' => [
                ['inputs' => ['ans1' => 'ta1'], 'expected' => ['prt1' => ['score' => 1]]],
                ['inputs' => ['ans1' => '{r2, r1}'], 'expected' => ['prt1' => ['score' => 1]]],
                ['inputs' => ['ans1' => '{r1}'], 'expected' => ['prt1' => ['score' => 0, 'penalty' => 0.1]]],
                ['inputs' => ['ans1' => '{r1, r2, -1}'], 'expected' => ['prt1' => ['score' => 0, 'penalty' => 0.1]]],
            ],
        ];
    }
}
