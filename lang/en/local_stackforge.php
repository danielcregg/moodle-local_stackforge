<?php
// Language strings for local_stackforge.
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'STACK Forge — AI question generator';

// Settings.
$string['serviceurl'] = 'Generation service URL';
$string['serviceurl_desc'] = 'Base URL of the stack-question-forge API (the host serving /generate, /render, …). The same URL students\' STACK questions are validated against.';
$string['apitoken'] = 'API token';
$string['apitoken_desc'] = 'Bearer token for the generation service (FORGE_API_SECRET). Stored server-side; never sent to the browser.';

// Capability.
$string['stackforge:generate'] = 'Generate STACK questions with AI';

// UI.
$string['generate'] = 'Generate STACK questions';
$string['generateheading'] = 'Auto-generate STACK questions with AI';
$string['intro'] = 'Draft new STACK questions with AI, validated on the live STACK engine, and add them straight to this course\'s question bank. Every question is proven gradable across random variants before it is added.';
$string['qtype'] = 'Question type';
$string['difficulty'] = 'Difficulty';
$string['count'] = 'How many';
$string['category'] = 'Add to category';
$string['generatebtn'] = 'Generate & add to question bank';
$string['notconfigured'] = 'The generation service is not configured. Set its URL + token in Site administration → Plugins → Local plugins → STACK Forge.';

// Difficulties.
$string['easy'] = 'Easy';
$string['medium'] = 'Medium';
$string['hard'] = 'Hard';

// Results.
$string['imported'] = 'Added {$a} validated question(s) to the question bank.';
$string['nonemade'] = 'No questions could be generated/validated. {$a}';
$string['servicefail'] = 'The generation service returned an error: {$a}';
$string['importfail'] = 'A generated question could not be imported into the question bank.';
$string['backtobank'] = 'Open the question bank';

// Privacy.
$string['privacy:metadata'] = 'The STACK Forge plugin does not store any personal data. It sends only the chosen question type and difficulty to the generation service to draft questions; no information about users is transmitted.';
