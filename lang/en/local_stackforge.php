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

$string['ai_key'] = 'AI API key';
$string['ai_key_desc'] = 'API key for the chosen provider. Stored server-side and never sent to the browser. Used only in in-process mode to draft expressions for the differentiate and integrate types.';
$string['ai_model'] = 'AI model';
$string['ai_model_desc'] = 'Model id for the chosen provider, for example gpt-4o-mini, claude-opus-4-6, or gemini-2.5-flash.';
$string['ai_provider'] = 'AI provider';
$string['ai_provider_desc'] = 'Which AI service drafts source expressions in in-process mode. Leave as "None" to use only the built-in template expressions (no AI calls are made).';
$string['ai_provider_none'] = 'None (templates only)';
$string['aibackend'] = 'AI backend (for drafting)';
$string['aibackend_auto'] = 'Auto — Moodle\'s built-in AI if a provider is configured, otherwise this plugin\'s own provider';
$string['aibackend_core'] = 'Moodle\'s built-in AI (reuses the site\'s configured provider and key)';
$string['aibackend_desc'] = 'How the source expression is drafted for the differentiate and integrate types. The oracle validates it either way, and the deterministic template default is used if no AI is available. "Moodle\'s built-in AI" reuses a provider configured under Site administration > AI, so no separate key is needed and Moodle\'s AI policy and logging apply. "This plugin\'s own provider" uses the AI provider, model and key below. "Auto" prefers Moodle\'s AI when available.';
$string['aibackend_own'] = 'This plugin\'s own provider and key (set below)';
$string['aifailed'] = 'The AI request failed: {$a}';
$string['aiheading'] = 'In-process AI (zero backend)';
$string['aiheading_desc'] = 'In-process mode validates questions against this site\'s own STACK / Maxima — no external server. It only needs an AI key to draft expressions for the differentiate and integrate types (the other six generate from built-in templates). The key is stored server-side and never reaches the browser. Alternatively, set the AI backend to Moodle\'s built-in AI to reuse a site-configured provider with no separate key.';
$string['aipolicynotice'] = 'AI drafting uses Moodle\'s built-in AI on this site. Accept the AI usage policy to enable it — questions still generate from templates without it.';
$string['apitoken'] = 'API token';
$string['apitoken_desc'] = 'Bearer token for the external generation service (the FORGE_API_SECRET value). Stored server-side and never sent to the browser. Leave blank if your endpoint needs no token, or if you use in-process mode.';
$string['backtobank'] = 'Open the question bank';
$string['backtosettings'] = 'Back to STACK Forge settings';
$string['badendpoint'] = 'The configured generation service URL is not a valid http(s) address.';
$string['buildquizbtn'] = 'Build RL-sequenced quiz';
$string['buildquizheading'] = 'Or build a full RL-sequenced quiz';
$string['buildquizintro'] = 'Generate a quiz whose questions follow the teaching policy\'s discovered easy to hard curriculum (added to the category selected above). Requires the external generation service.';
$string['builtset'] = 'Generated {$a} questions in the teaching policy\'s curriculum order.';
$string['category'] = 'Add to category';
$string['cleanuptask'] = 'Clean up stale STACK Forge scratch categories';
$string['count'] = 'How many';
$string['difficulty'] = 'Difficulty';
$string['easy'] = 'Easy';
$string['externalheading'] = 'External generation service (fallback)';
$string['externalheading_desc'] = 'Optional. The original decoupled backend (the stack-question-forge pipeline). Configure this only if you use external mode, or as the auto-mode fallback. In-process mode needs none of this.';
$string['generate'] = 'Generate STACK questions';
$string['generatebtn'] = 'Generate & add to question bank';
$string['generateheading'] = 'Auto-generate STACK questions with AI';
$string['hard'] = 'Hard';
$string['imported'] = 'Added {$a} validated question(s) to the question bank.';
$string['intro'] = 'Draft new STACK questions with AI, validated on a live STACK engine, and add them straight to this course\'s question bank. Every question is proven gradable across random variants before it is added.';
$string['jobfailed'] = 'No questions could be generated or validated. See the log below.';
$string['jobqueued'] = 'Your generation job is queued. This page refreshes automatically; questions appear once the site\'s scheduled tasks run.';
$string['jobrunning'] = 'Generating... {$a->made} of {$a->total} done. This page refreshes automatically.';
$string['medium'] = 'Medium';
$string['mode'] = 'Generation mode';
$string['mode_auto'] = 'Auto (external if a service URL is set, otherwise in-process)';
$string['mode_desc'] = 'How questions are drafted and validated. In-process uses this site\'s own STACK / Maxima (no backend). External calls the stack-question-forge service. Auto keeps using an external service if its URL is already configured (so an existing site is never silently switched), and otherwise runs in-process.';
$string['mode_external'] = 'External generation service';
$string['mode_inprocess'] = 'In-process (this site\'s STACK / Maxima)';
$string['modebanner'] = 'Generation mode: {$a}.';
$string['nonemade'] = 'No questions could be generated or validated. {$a}';
$string['notconfigured'] = 'External mode is selected but no generation service URL is set. Configure it in Site administration > Plugins > Local plugins > STACK Forge, or switch the mode to In-process.';
$string['openquiz'] = 'Open the new quiz';
$string['pluginname'] = 'STACK Forge — AI question generator';
$string['policyaccept'] = 'Accept the AI policy';
$string['policyaccepted'] = 'AI usage policy accepted — AI drafting is now enabled.';
$string['policyacceptfailed'] = 'Could not record your AI policy acceptance. Please try again.';
$string['privacy:metadata:aiservice'] = 'To draft expressions in in-process mode, the chosen question type and difficulty are sent to the configured AI provider. No information about any user is transmitted.';
$string['privacy:metadata:aiservice:difficulty'] = 'The requested difficulty (not personal data).';
$string['privacy:metadata:aiservice:qtype'] = 'The question type, for example differentiate (not personal data).';
$string['privacy:metadata:local_stackforge_jobs'] = 'Records of STACK question generation jobs requested by users.';
$string['privacy:metadata:local_stackforge_jobs:courseid'] = 'The course the questions were generated into.';
$string['privacy:metadata:local_stackforge_jobs:difficulty'] = 'The requested difficulty.';
$string['privacy:metadata:local_stackforge_jobs:qtype'] = 'The STACK question type requested.';
$string['privacy:metadata:local_stackforge_jobs:status'] = 'The status of the generation job.';
$string['privacy:metadata:local_stackforge_jobs:timecreated'] = 'When the generation job was requested.';
$string['privacy:metadata:local_stackforge_jobs:userid'] = 'The user who requested the generation job.';
$string['qtype'] = 'Question type';
$string['qtypeversionwarning'] = 'STACK Forge\'s in-process mode was verified against qtype_stack version {$a->tested}. This site has version {$a->installed}. In-process generation may still work, but run the in-process smoke test to confirm before relying on it this term.';
$string['quizintro'] = 'Auto-built from the teaching policy: the questions follow the policy\'s easy to hard curriculum.';
$string['quizname'] = 'RL Adaptive Quiz ({$a})';
$string['quiznotbuilt'] = 'The questions were added to the question bank, but the quiz could not be auto-created on this site. Build a quiz from the category manually.';
$string['seqcount'] = 'Number of questions';
$string['servicefail'] = 'The generation service returned an error: {$a}';
$string['serviceurl'] = 'Generation service URL';
$string['serviceurl_desc'] = 'Base URL of the external generation service (the host serving /generate and /sequence). Only needed for external or auto mode; leave blank for in-process mode.';
$string['smoke_desc'] = 'Run a one-question in-process smoke test (build, validate against Maxima, delete): <a href="{$a}">run the smoke test</a>.';
$string['smokefail'] = 'In-process smoke test FAILED: {$a}';
$string['smokeheading'] = 'In-process smoke test';
$string['smokeintro'] = 'This builds one known-good "differentiate" question, validates it across all deployed seeds against this site\'s Maxima, then deletes it. Use it to confirm in-process mode works on this site.';
$string['smokepass'] = 'In-process smoke test PASSED — validated across {$a->seeds} seeds and {$a->tests} question-tests in {$a->ms} ms.';
$string['smokerun'] = 'Run smoke test';
$string['smokeunsupported'] = 'In-process mode is not available on this site: {$a}';
$string['stackforge:generate'] = 'Generate STACK questions with AI';
$string['type_differentiate'] = 'Differentiate';
$string['type_expand'] = 'Expand';
$string['type_factor'] = 'Factorise';
$string['type_integrate'] = 'Find an antiderivative';
$string['type_numerical'] = 'Evaluate to a decimal';
$string['type_simplify'] = 'Simplify to lowest terms';
$string['type_solvelinear'] = 'Solve a linear equation';
$string['type_solvequadratic'] = 'Solve a quadratic';
