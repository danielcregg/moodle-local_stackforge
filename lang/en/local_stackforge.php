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
 * English language strings for local_stackforge.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['apitoken'] = 'API token';
$string['apitoken_desc'] = 'Bearer token for the generation service (the FORGE_API_SECRET value). Stored server-side and never sent to the browser. Leave blank if your endpoint needs no token.';
$string['backtobank'] = 'Open the question bank';
$string['badendpoint'] = 'The configured generation service URL is not a valid http(s) address.';
$string['buildquizbtn'] = 'Build RL-sequenced quiz';
$string['buildquizheading'] = 'Or build a full RL-sequenced quiz';
$string['buildquizintro'] = 'Generate a quiz whose questions follow the teaching policy\'s discovered easy → hard curriculum (added to the category selected above).';
$string['builtset'] = 'Generated {$a} questions in the teaching policy\'s curriculum order.';
$string['category'] = 'Add to category';
$string['count'] = 'How many';
$string['difficulty'] = 'Difficulty';
$string['easy'] = 'Easy';
$string['generate'] = 'Generate STACK questions';
$string['generatebtn'] = 'Generate & add to question bank';
$string['generateheading'] = 'Auto-generate STACK questions with AI';
$string['hard'] = 'Hard';
$string['imported'] = 'Added {$a} validated question(s) to the question bank.';
$string['intro'] = 'Draft new STACK questions with AI, validated on a live STACK engine, and add them straight to this course\'s question bank. Every question is proven gradable across random variants before it is added.';
$string['medium'] = 'Medium';
$string['nonemade'] = 'No questions could be generated or validated. {$a}';
$string['notconfigured'] = 'The generation service is not configured. Set its URL and token in Site administration → Plugins → Local plugins → STACK Forge.';
$string['openquiz'] = 'Open the new quiz';
$string['pluginname'] = 'STACK Forge — AI question generator';
$string['privacy:metadata'] = 'The STACK Forge plugin does not store any personal data. It sends only the chosen question type and difficulty to the generation service to draft questions; no information about users is transmitted.';
$string['qtype'] = 'Question type';
$string['quizintro'] = 'Auto-built from the teaching policy: the questions follow the policy\'s easy → hard curriculum.';
$string['quizname'] = 'RL Adaptive Quiz ({$a})';
$string['quiznotbuilt'] = 'The questions were added to the question bank, but the quiz could not be auto-created on this site. Build a quiz from the category manually.';
$string['seqcount'] = 'Number of questions';
$string['servicefail'] = 'The generation service returned an error: {$a}';
$string['serviceurl'] = 'Generation service URL';
$string['serviceurl_desc'] = 'Base URL of the generation service (the host serving /generate and /sequence) — the same backend your STACK questions are validated against. Required; leave blank to disable the plugin.';
$string['stackforge:generate'] = 'Generate STACK questions with AI';
$string['type_differentiate'] = 'Differentiate';
$string['type_expand'] = 'Expand';
$string['type_factor'] = 'Factorise';
$string['type_integrate'] = 'Find an antiderivative';
$string['type_numerical'] = 'Evaluate to a decimal';
$string['type_simplify'] = 'Simplify to lowest terms';
$string['type_solvelinear'] = 'Solve a linear equation';
$string['type_solvequadratic'] = 'Solve a quadratic';
