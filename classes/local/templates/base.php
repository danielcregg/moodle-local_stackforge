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
 * Base class for STACK question-type templates.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local\templates;

/**
 * A template deterministically builds a structured question array from a slot. The AI's only job
 * is to (optionally) supply the slot's expr within a safe grammar; the question's own Maxima then
 * COMPUTES the teacher answer, so the answer is correct by construction (the oracle).
 */
abstract class base {
    /**
     * Build a structured question array (consumed by question_xml::build) from a slot.
     *
     * @param array $slot Optional fields: expr, difficulty, name, deployedSeeds.
     * @return array The structured question.
     */
    abstract public static function make(array $slot = []): array;

    /**
     * Safe fallback expressions for the expr-driven types (empty for self-parameterised types).
     *
     * @return string[] The default expressions.
     */
    public static function default_exprs(): array {
        return [];
    }
}
