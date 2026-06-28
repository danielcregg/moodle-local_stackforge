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
 * Tests for the qtype_stack version-drift warning.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * The in-process validator couples to qtype_stack internals; this confirms the proactive
 * "STACK was upgraded past the tested version" heads-up fires only when it should.
 *
 * @covers \local_stackforge\local\inprocess_validator
 */
final class inprocess_validator_test extends \advanced_testcase {
    /**
     * version_warning() is null when qtype_stack is absent or at/below the tested version, and returns
     * the installed + tested versions only when the installed one is newer.
     *
     * @return void
     */
    public function test_version_warning(): void {
        $this->resetAfterTest();
        $tested = inprocess_validator::tested_qtype_stack();

        // Absent qtype_stack: no warning.
        set_config('version', 0, 'qtype_stack');
        $this->assertNull(inprocess_validator::version_warning());

        // Exactly the tested version: no warning.
        set_config('version', $tested, 'qtype_stack');
        $this->assertNull(inprocess_validator::version_warning());

        // Older than tested: no warning (the feature-probe handles a missing API instead).
        set_config('version', $tested - 1, 'qtype_stack');
        $this->assertNull(inprocess_validator::version_warning());

        // Newer than tested: warning carrying both versions.
        set_config('version', $tested + 1, 'qtype_stack');
        $warning = inprocess_validator::version_warning();
        $this->assertIsArray($warning);
        $this->assertSame($tested, $warning['tested']);
        $this->assertSame($tested + 1, $warning['installed']);
    }
}
