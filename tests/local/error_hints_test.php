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
 * Tests for the Maxima-reason to retry-hint catalog.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * The POST-stage retry catalog is pure; it turns an oracle failure reason into a short, non-empty hint.
 *
 * @covers \local_stackforge\local\error_hints
 */
final class error_hints_test extends \advanced_testcase {
    /**
     * Every failure reason the in-process validator can emit maps to a non-empty hint.
     *
     * @return void
     */
    public function test_known_reasons_map_to_nonempty_hints(): void {
        $reasons = [
            'runtime CAS error (seed 3): Division by zero',
            'instantiation error (seed 17): something',
            'answer-note disagreement across seeds (tc 1, prt1): [prt1-1-T, prt1-1-F]',
            'validate_for_bulk (seed 3): bad expression',
            'stackversion check (seed 3): unsupported',
            'PRT runtime error (seed 3, tc 1, prt1): boom',
            'final test failed (seed 3, tc 1): score mismatch',
            'final test error (seed 3): threw',
            'unsafe or unparseable expression',
        ];
        foreach ($reasons as $reason) {
            $hint = error_hints::hint_for($reason, 'differentiate');
            $this->assertNotEmpty($hint, "empty hint for: $reason");
            $this->assertIsString($hint);
        }
    }

    /**
     * The integrate noun-form / division-by-zero failure gets integrand-specific guidance.
     *
     * @return void
     */
    public function test_noun_form_is_integrate_specific(): void {
        $reason = 'runtime CAS error (seed 3): Division by zero';
        $integrate = error_hints::hint_for($reason, 'integrate');
        $this->assertStringContainsStringIgnoringCase('elementary', $integrate);

        // The same reason for a non-integrate type gives a different, generic hint.
        $differentiate = error_hints::hint_for($reason, 'differentiate');
        $this->assertNotSame($integrate, $differentiate);
        $this->assertNotEmpty($differentiate);
    }

    /**
     * An unrecognised reason still yields a safe, non-empty generic hint.
     *
     * @return void
     */
    public function test_unknown_reason_is_generic(): void {
        $hint = error_hints::hint_for('some brand new failure mode', 'integrate');
        $this->assertNotEmpty($hint);
        $this->assertStringContainsStringIgnoringCase('polynomial', $hint);
    }
}
