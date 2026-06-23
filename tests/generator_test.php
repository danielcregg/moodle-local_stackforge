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
 * Unit tests for the STACK Forge generation-service client and importer.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge;

/**
 * Tests endpoint validation and the import shape guard (defence in depth).
 *
 * @covers \local_stackforge\generator
 */
final class generator_test extends \advanced_testcase {

    /**
     * An empty service URL is reported as not configured, before any network call.
     *
     * @return void
     */
    public function test_generate_requires_serviceurl(): void {
        $this->resetAfterTest();
        set_config('serviceurl', '', 'local_stackforge');
        try {
            generator::generate('differentiate', 'easy', 1);
            $this->fail('Expected a moodle_exception for a missing service URL.');
        } catch (\moodle_exception $e) {
            $this->assertSame('notconfigured', $e->errorcode);
        }
    }

    /**
     * A non-http(s) scheme is rejected, before any network call.
     *
     * @return void
     */
    public function test_generate_rejects_non_http_scheme(): void {
        $this->resetAfterTest();
        set_config('serviceurl', 'ftp://example.com/path', 'local_stackforge');
        try {
            generator::generate('differentiate', 'easy', 1);
            $this->fail('Expected a moodle_exception for a non-http(s) scheme.');
        } catch (\moodle_exception $e) {
            $this->assertSame('badendpoint', $e->errorcode);
        }
    }

    /**
     * A URL carrying embedded credentials is rejected, before any network call.
     *
     * @return void
     */
    public function test_generate_rejects_url_with_credentials(): void {
        $this->resetAfterTest();
        set_config('serviceurl', 'http://user:pass@example.com', 'local_stackforge');
        try {
            generator::generate('differentiate', 'easy', 1);
            $this->fail('Expected a moodle_exception for credentials in the URL.');
        } catch (\moodle_exception $e) {
            $this->assertSame('badendpoint', $e->errorcode);
        }
    }

    /**
     * Create a throwaway course, context and question category for import tests.
     *
     * @return array The course, context and category objects, in that order.
     */
    private function make_target(): array {
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $category = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question_category(['contextid' => $context->id]);
        return [$course, $context, $category];
    }

    /**
     * The import guard rejects XML that is not a single STACK question.
     *
     * @return void
     */
    public function test_import_one_rejects_non_stack_xml(): void {
        $this->resetAfterTest();
        [$course, $context, $category] = $this->make_target();
        $xml = '<?xml version="1.0"?><quiz><question type="essay"><name><text>E</text></name>'
            . '</question></quiz>';
        $this->assertSame([], generator::import_one($xml, $category, $context, $course));
    }

    /**
     * The import guard rejects more than one STACK question in the document.
     *
     * @return void
     */
    public function test_import_one_rejects_multiple_stack_questions(): void {
        $this->resetAfterTest();
        [$course, $context, $category] = $this->make_target();
        $xml = '<?xml version="1.0"?><quiz>'
            . '<question type="stack"><name><text>A</text></name></question>'
            . '<question type="stack"><name><text>B</text></name></question></quiz>';
        $this->assertSame([], generator::import_one($xml, $category, $context, $course));
    }

    /**
     * The import guard rejects oversized documents.
     *
     * @return void
     */
    public function test_import_one_rejects_oversized_xml(): void {
        $this->resetAfterTest();
        [$course, $context, $category] = $this->make_target();
        $xml = '<quiz><question type="stack"></question></quiz>' . str_repeat(' ', 200001);
        $this->assertSame([], generator::import_one($xml, $category, $context, $course));
    }
}
