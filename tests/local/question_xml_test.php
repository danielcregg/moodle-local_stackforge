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
 * Tests for the deterministic STACK XML builder and the templates.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * The builder is a pure PHP port of the JS pipeline that produced the validated seed bank.
 *
 * @covers \local_stackforge\local\question_xml
 * @covers \local_stackforge\local\template_registry
 */
final class question_xml_test extends \advanced_testcase {
    /**
     * Escaping is sequential (ampersand first) and CDATA splits any embedded terminator.
     *
     * @return void
     */
    public function test_escape_and_cdata(): void {
        $this->assertSame('&lt;a&gt;&amp;&quot;&apos;', question_xml::escape_xml('<a>&"\''));
        $this->assertStringContainsString(']]]]><![CDATA[>', question_xml::cdata('a]]>b'));
    }

    /**
     * Every one of the eight types builds well-formed XML with the expected STACK skeleton.
     *
     * @return void
     */
    public function test_all_types_build_valid_xml(): void {
        $this->assertCount(8, template_registry::types());
        foreach (template_registry::types() as $type) {
            $q = template_registry::make($type, ['difficulty' => 'easy']);
            $this->assertIsArray($q, "template make for $type");
            $xml = question_xml::build($q);
            $doc = new \DOMDocument();
            $this->assertTrue(@$doc->loadXML($xml), "well-formed XML for $type");
            $this->assertStringContainsString('<question type="stack">', $xml);
            $this->assertStringContainsString('<deployedseed>3</deployedseed>', $xml);
            $this->assertStringContainsString('ta1', $xml);
        }
    }

    /**
     * The differentiate template interpolates the expression and the computed teacher answer.
     *
     * @return void
     */
    public function test_differentiate_structure(): void {
        $xml = question_xml::build(template_registry::make('differentiate', ['expr' => '(x-a)^3']));
        $this->assertStringContainsString('expr : (x-a)^3;', $xml);
        $this->assertStringContainsString('ta1 : diff(expr, x);', $xml);
        $this->assertStringContainsString('<answertest>AlgEquiv</answertest>', $xml);
        $this->assertStringContainsString('<expectedscore>1.0000000</expectedscore>', $xml);
    }

    /**
     * Scores render as 7-decimal STACK format; defaultgrade/penalty render minimally.
     *
     * @return void
     */
    public function test_score_format(): void {
        $xml = question_xml::build(template_registry::make('numerical'));
        $this->assertStringContainsString('<value>1.0000000</value>', $xml);
        $this->assertStringContainsString('<penalty>0.1</penalty>', $xml);
        $this->assertStringContainsString('<defaultgrade>1</defaultgrade>', $xml);
    }

    /**
     * Unknown types return null from the registry.
     *
     * @return void
     */
    public function test_unknown_type(): void {
        $this->assertNull(template_registry::make('not_a_type'));
        $this->assertFalse(template_registry::exists('not_a_type'));
    }
}
