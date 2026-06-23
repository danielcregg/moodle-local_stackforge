<?php
// STACK Forge - a thin Moodle plugin that auto-generates VALIDATED STACK questions into the
// course question bank by calling the external generation service (the forge pipeline). The AI
// key + the oracle live server-side; this plugin just asks for questions and imports them.
// Phase 2.5 of stack-question-forge (bringing question authoring inside Moodle).
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_stackforge';
$plugin->version   = 2026062301;
$plugin->requires  = 2024042200;   // Moodle 4.4+
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0';
