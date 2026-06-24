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
 * Tests for mode resolution and AI client configuration gating.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * Mode resolution must respect an existing external service (no silent switch on upgrade), and the
 * AI client must refuse to act until fully configured.
 *
 * @covers \local_stackforge\local\pipeline
 * @covers \local_stackforge\local\ai_client
 */
final class pipeline_test extends \advanced_testcase {
    /**
     * Explicit modes pass through; auto picks external when a service URL is set, else in-process.
     *
     * @return void
     */
    public function test_resolve_mode(): void {
        $this->resetAfterTest();

        set_config('mode', 'external', 'local_stackforge');
        $this->assertSame('external', pipeline::resolve_mode());

        set_config('mode', 'inprocess', 'local_stackforge');
        $this->assertSame('inprocess', pipeline::resolve_mode());

        set_config('mode', 'auto', 'local_stackforge');
        set_config('serviceurl', 'http://example.invalid', 'local_stackforge');
        $this->assertSame('external', pipeline::resolve_mode());

        set_config('serviceurl', '', 'local_stackforge');
        $this->assertSame('inprocess', pipeline::resolve_mode());
    }

    /**
     * The AI client knows which types consume an expression and refuses to act unconfigured.
     *
     * @return void
     */
    public function test_ai_client_config(): void {
        $this->resetAfterTest();

        $this->assertTrue(ai_client::uses_expr('differentiate'));
        $this->assertTrue(ai_client::uses_expr('integrate'));
        $this->assertFalse(ai_client::uses_expr('expand'));

        $this->assertFalse(ai_client::configured());
        $this->assertNull(ai_client::propose_expr('differentiate', 'easy'));

        set_config('ai_provider', 'openai', 'local_stackforge');
        set_config('ai_model', 'gpt-4o-mini', 'local_stackforge');
        set_config('ai_key', 'sk-test-not-real', 'local_stackforge');
        $this->assertTrue(ai_client::configured());
    }
}
