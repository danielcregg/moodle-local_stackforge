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
 * Registry of question-type templates.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * Maps each forge question type to its template class. Mirrors docs/js/templates/index.js.
 */
class template_registry {
    /**
     * Type code => template class name.
     *
     * @var array<string, class-string>
     */
    const MAP = [
        'differentiate'         => templates\differentiate::class,
        'solve_linear'          => templates\solve_linear::class,
        'numerical'             => templates\numerical::class,
        'integrate'             => templates\integrate::class,
        'expand'                => templates\expand::class,
        'factor'                => templates\factor::class,
        'simplify_lowest_terms' => templates\simplify_lowest_terms::class,
        'solve_quadratic'       => templates\solve_quadratic::class,
    ];

    /**
     * The known type codes.
     *
     * @return string[] The list of type codes.
     */
    public static function types(): array {
        return array_keys(self::MAP);
    }

    /**
     * Whether a type code is known.
     *
     * @param string $type The type code.
     * @return bool True if a template exists for the type.
     */
    public static function exists(string $type): bool {
        return isset(self::MAP[$type]);
    }

    /**
     * Build a structured question for a type from a slot.
     *
     * @param string $type The type code.
     * @param array $slot The slot (expr, difficulty, ...).
     * @return array|null The structured question, or null if the type is unknown.
     */
    public static function make(string $type, array $slot = []): ?array {
        if (!isset(self::MAP[$type])) {
            return null;
        }
        $class = self::MAP[$type];
        return $class::make($slot);
    }

    /**
     * The fallback expressions for a type (empty for self-parameterised types).
     *
     * @param string $type The type code.
     * @return string[] The default expressions.
     */
    public static function default_exprs(string $type): array {
        if (!isset(self::MAP[$type])) {
            return [];
        }
        $class = self::MAP[$type];
        return $class::default_exprs();
    }
}
