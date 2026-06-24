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
 * Build Moodle STACK question XML from a structured question array.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * Pure, dependency-free STACK XML builder. A faithful PHP port of docs/js/question-xml.js so the
 * in-process pipeline emits exactly the same XML the validated seed bank was built from.
 *
 * Grounded in the real qtype_stack export format: inputs and PRTs use CHILD ELEMENTS (not
 * attributes), scores are 7-dp, and answer notes are 1-based (prt1-1-T) even though node name
 * is 0-based.
 */
class question_xml {
    /**
     * XML-escape a value (sequential replace, ampersand first), mirroring the JS escapeXml.
     *
     * @param mixed $s The value to escape.
     * @return string The escaped string.
     */
    public static function escape_xml($s): string {
        $s = (string) ($s ?? '');
        return str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
            $s
        );
    }

    /**
     * Wrap a value in a CDATA section, splitting any ]]> so the content cannot terminate it early.
     *
     * @param mixed $s The value to wrap.
     * @return string The CDATA section.
     */
    public static function cdata($s): string {
        $s = (string) ($s ?? '');
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $s) . ']]>';
    }

    /**
     * A &lt;text&gt; element carrying HTML/Maxima content (CDATA-wrapped), empty as &lt;text/&gt;.
     *
     * @param mixed $s The content.
     * @return string The text element.
     */
    protected static function html_text($s): string {
        return ($s === null || $s === '') ? '<text/>' : '<text>' . self::cdata($s) . '</text>';
    }

    /**
     * A &lt;text&gt; element carrying plain (escaped) content, empty as &lt;text/&gt;.
     *
     * @param mixed $s The content.
     * @return string The text element.
     */
    protected static function plain_text($s): string {
        return ($s === null || $s === '') ? '<text/>' : '<text>' . self::escape_xml($s) . '</text>';
    }

    /**
     * Format a score in STACK's 7-decimal form, e.g. 1.0000000.
     *
     * @param mixed $n The numeric score.
     * @return string The formatted score.
     */
    protected static function score($n): string {
        return number_format((float) $n, 7, '.', '');
    }

    /**
     * Format an optional score: empty string when the value is null/'', otherwise the 7-dp score.
     *
     * @param mixed $n The numeric score or null.
     * @return string The formatted score or empty string.
     */
    protected static function opt_score($n): string {
        return ($n === null || $n === '') ? '' : self::score($n);
    }

    /**
     * Stringify a plain number the way JavaScript's Number() does: integers without a decimal
     * point (1), non-integers minimally (0.1). Used for defaultgrade/penalty.
     *
     * @param mixed $n The number.
     * @return string The minimal string form.
     */
    protected static function num($n): string {
        $f = (float) $n;
        if ($f === (float) (int) $f) {
            return (string) (int) $f;
        }
        return rtrim(rtrim(sprintf('%.10f', $f), '0'), '.');
    }

    /**
     * Build one &lt;input&gt; block.
     *
     * @param array $inp The input definition.
     * @return string The input XML.
     */
    protected static function build_input(array $inp): string {
        return implode("\n", [
            '    <input>',
            '      <name>' . self::escape_xml($inp['name']) . '</name>',
            '      <type>' . self::escape_xml($inp['type'] ?? 'algebraic') . '</type>',
            '      <tans>' . self::escape_xml($inp['tans']) . '</tans>',
            '      <boxsize>' . (int) ($inp['boxSize'] ?? 15) . '</boxsize>',
            '      <strictsyntax>' . ($inp['strictSyntax'] ?? 1) . '</strictsyntax>',
            '      <insertstars>' . ($inp['insertStars'] ?? 0) . '</insertstars>',
            '      <syntaxhint>' . self::escape_xml($inp['syntaxHint'] ?? '') . '</syntaxhint>',
            '      <syntaxattribute>0</syntaxattribute>',
            '      <forbidwords>' . self::escape_xml($inp['forbidWords'] ?? '') . '</forbidwords>',
            '      <allowwords>' . self::escape_xml($inp['allowWords'] ?? '') . '</allowwords>',
            '      <forbidfloat>' . ($inp['forbidFloat'] ?? 1) . '</forbidfloat>',
            '      <requirelowestterms>' . ($inp['requireLowestTerms'] ?? 0) . '</requirelowestterms>',
            '      <checkanswertype>' . ($inp['checkAnswerType'] ?? 0) . '</checkanswertype>',
            '      <mustverify>' . ($inp['mustVerify'] ?? 1) . '</mustverify>',
            '      <showvalidation>' . ($inp['showValidation'] ?? 1) . '</showvalidation>',
            '      <options/>',
            '    </input>',
        ]);
    }

    /**
     * Build one PRT &lt;node&gt; block. Answer notes are 1-based even though node name is 0-based.
     *
     * @param array $node The node definition.
     * @param int $i The zero-based node index.
     * @param string $prtname The owning PRT name (for default answer notes).
     * @return string The node XML.
     */
    protected static function build_node(array $node, int $i, string $prtname): string {
        $note = $i + 1;
        return implode("\n", [
            '      <node>',
            '        <name>' . self::escape_xml($node['name'] ?? (string) $i) . '</name>',
            '        <description/>',
            '        <answertest>' . self::escape_xml($node['answerTest']) . '</answertest>',
            '        <sans>' . self::escape_xml($node['sAns']) . '</sans>',
            '        <tans>' . self::escape_xml($node['tAns']) . '</tans>',
            '        <testoptions>' . self::escape_xml($node['testOptions'] ?? '') . '</testoptions>',
            '        <quiet>' . ($node['quiet'] ?? 0) . '</quiet>',
            '        <truescoremode>' . self::escape_xml($node['trueScoreMode'] ?? '=') . '</truescoremode>',
            '        <truescore>' . self::score($node['trueScore'] ?? 1) . '</truescore>',
            '        <truepenalty>' . self::opt_score($node['truePenalty'] ?? null) . '</truepenalty>',
            '        <truenextnode>' . ($node['trueNextNode'] ?? -1) . '</truenextnode>',
            '        <trueanswernote>' . self::escape_xml($node['trueAnswerNote'] ?? "{$prtname}-{$note}-T") . '</trueanswernote>',
            '        <truefeedback format="html">' . self::html_text($node['trueFeedback'] ?? null) . '</truefeedback>',
            '        <falsescoremode>' . self::escape_xml($node['falseScoreMode'] ?? '=') . '</falsescoremode>',
            '        <falsescore>' . self::score($node['falseScore'] ?? 0) . '</falsescore>',
            '        <falsepenalty>' . self::opt_score($node['falsePenalty'] ?? null) . '</falsepenalty>',
            '        <falsenextnode>' . ($node['falseNextNode'] ?? -1) . '</falsenextnode>',
            '        <falseanswernote>' . self::escape_xml($node['falseAnswerNote'] ?? "{$prtname}-{$note}-F") . '</falseanswernote>',
            '        <falsefeedback format="html">' . self::html_text($node['falseFeedback'] ?? null) . '</falsefeedback>',
            '      </node>',
        ]);
    }

    /**
     * Build one &lt;prt&gt; block.
     *
     * @param array $prt The PRT definition.
     * @return string The PRT XML.
     */
    protected static function build_prt(array $prt): string {
        $nodes = [];
        foreach (($prt['nodes'] ?? []) as $i => $node) {
            $nodes[] = self::build_node($node, $i, $prt['name']);
        }
        return implode("\n", [
            '    <prt>',
            '      <name>' . self::escape_xml($prt['name']) . '</name>',
            '      <value>' . self::score($prt['value'] ?? 1) . '</value>',
            '      <autosimplify>' . ($prt['autoSimplify'] ?? 1) . '</autosimplify>',
            '      <feedbackstyle>' . ($prt['feedbackStyle'] ?? 1) . '</feedbackstyle>',
            '      <feedbackvariables>' . self::html_text($prt['feedbackVariables'] ?? null) . '</feedbackvariables>',
            implode("\n", $nodes),
            '    </prt>',
        ]);
    }

    /**
     * Build one &lt;qtest&gt; block.
     *
     * @param array $t The test case.
     * @param int $i The zero-based test index (for the default 1-based testcase number).
     * @return string The qtest XML.
     */
    protected static function build_qtest(array $t, int $i): string {
        $inputs = [];
        foreach (($t['inputs'] ?? []) as $name => $value) {
            $inputs[] = implode("\n", [
                '      <testinput>',
                '        <name>' . self::escape_xml($name) . '</name>',
                '        <value>' . self::escape_xml($value) . '</value>',
                '      </testinput>',
            ]);
        }
        $expected = [];
        foreach (($t['expected'] ?? []) as $prt => $e) {
            $lines = [
                '      <expected>',
                '        <name>' . self::escape_xml($prt) . '</name>',
                '        <expectedscore>' . self::score($e['score'] ?? 0) . '</expectedscore>',
                '        <expectedpenalty>' . self::score($e['penalty'] ?? 0) . '</expectedpenalty>',
            ];
            // Only assert the answer note when explicitly provided - notes embed answer-test-specific
            // tokens (e.g. ATExpanded_TRUE.) that are brittle to predict.
            if (!empty($e['answerNote'])) {
                $lines[] = '        <expectedanswernote>' . self::escape_xml($e['answerNote']) . '</expectedanswernote>';
            }
            $lines[] = '      </expected>';
            $expected[] = implode("\n", $lines);
        }
        return implode("\n", [
            '    <qtest>',
            '      <testcase>' . ($t['case'] ?? $i + 1) . '</testcase>',
            '      <description/>',
            implode("\n", $inputs),
            implode("\n", $expected),
            '    </qtest>',
        ]);
    }

    /**
     * Build the full &lt;quiz&gt;&lt;question type="stack"&gt;...&lt;/question&gt;&lt;/quiz&gt; document.
     *
     * @param array $q The structured question (produced by a template).
     * @return string The complete STACK question XML document.
     */
    public static function build(array $q): string {
        if (array_key_exists('specificFeedback', $q) && $q['specificFeedback'] !== null) {
            $specific = $q['specificFeedback'];
        } else {
            $fb = [];
            foreach (($q['prts'] ?? []) as $p) {
                $fb[] = "[[feedback:{$p['name']}]]";
            }
            $specific = implode("\n", $fb);
        }

        $seeds = [];
        foreach (($q['deployedSeeds'] ?? []) as $s) {
            $seeds[] = '    <deployedseed>' . (int) $s . '</deployedseed>';
        }
        $inputs = [];
        foreach (($q['inputs'] ?? []) as $inp) {
            $inputs[] = self::build_input($inp);
        }
        $prts = [];
        foreach (($q['prts'] ?? []) as $prt) {
            $prts[] = self::build_prt($prt);
        }
        $tests = [];
        foreach (($q['tests'] ?? []) as $i => $t) {
            $tests[] = self::build_qtest($t, $i);
        }

        $name = (isset($q['name']) && $q['name'] !== '') ? $q['name'] : 'Question';

        $parts = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<quiz>',
            '  <question type="stack">',
            '    <name>' . self::plain_text($name) . '</name>',
            '    <questiontext format="html">' . self::html_text($q['questionText'] ?? null) . '</questiontext>',
            '    <generalfeedback format="html">' . self::html_text($q['generalFeedback'] ?? null) . '</generalfeedback>',
            '    <defaultgrade>' . self::num($q['defaultGrade'] ?? 1) . '</defaultgrade>',
            '    <penalty>' . self::num($q['penalty'] ?? 0.1) . '</penalty>',
            '    <hidden>0</hidden>',
            '    <idnumber/>',
            '    <stackversion>' . self::plain_text($q['stackVersion'] ?? null) . '</stackversion>',
            '    <questionvariables>' . self::html_text($q['questionVariables'] ?? null) . '</questionvariables>',
            '    <specificfeedback format="html">' . self::html_text($specific) . '</specificfeedback>',
            '    <questionnote format="html">' . self::html_text($q['questionNote'] ?? null) . '</questionnote>',
            '    <questiondescription format="html"><text/></questiondescription>',
            '    <questionsimplify>' . (int) ($q['questionSimplify'] ?? 1) . '</questionsimplify>',
            '    <assumepositive>0</assumepositive>',
            '    <assumereal>0</assumereal>',
            '    <decimals>.</decimals>',
            '    <scientificnotation>*10</scientificnotation>',
            '    <multiplicationsign>dot</multiplicationsign>',
            '    <sqrtsign>1</sqrtsign>',
            '    <complexno>i</complexno>',
            '    <inversetrig>cos-1</inversetrig>',
            '    <logicsymbol>lang</logicsymbol>',
            '    <matrixparens>[</matrixparens>',
            '    <variantsselectionseed/>',
            implode("\n", $seeds),
            implode("\n", $inputs),
            implode("\n", $prts),
            implode("\n", $tests),
            '  </question>',
            '</quiz>',
            '',
        ];
        return implode("\n", $parts);
    }
}
