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
 * Version metadata for the STACK Forge local plugin.
 *
 * A thin plugin that auto-generates oracle-validated STACK questions into the course question
 * bank by calling an external generation service (the stack-question-forge pipeline). The AI key
 * and the Maxima oracle live server-side; this plugin only requests questions and imports them.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component    = 'local_stackforge';
$plugin->version      = 2026062400;
$plugin->requires     = 2024100700;             // Moodle 4.5 (LTS).
$plugin->supported    = [405, 405];             // Developed and tested on Moodle 4.5 LTS.
$plugin->maturity     = MATURITY_BETA;
$plugin->release      = '1.0.0-beta';
$plugin->dependencies = [
    'qtype_stack' => ANY_VERSION,               // Generates and imports STACK questions only.
];
