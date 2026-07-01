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
 * Backend-agnostic prompt authoring for the source-expression proposal.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * The single builder both the server client (ai_client::propose_expr) and the on-device browser driver
 * consume, so the PRE stage is identical across every backend. It emits a minimal-JSON contract
 * ({"expr": "..."}) with one worked few-shot example, difficulty guidance, an AVOID block for recent
 * Maxima failures, and an anti-duplication list of expressions already used this batch. Everything here
 * is pure (no I/O), so it is unit-testable and runs in CI without Maxima. Only the two expr-driven types
 * (differentiate, integrate) produce a prompt; the other six are template-only and never call the AI.
 */
class prompt_rules {
    /** @var string The compact tier: terse, one few-shot, for small / on-device models. */
    const TIER_COMPACT = 'compact';

    /** @var string The verbose tier: RIGHT/WRONG examples, for capable cloud models. */
    const TIER_VERBOSE = 'verbose';

    /**
     * Per-type metadata: the display label, a one-line description of the required expression, and a set
     * of worked example expressions (all grammar-safe polynomials the templates already ship).
     *
     * @var array Per-type metadata keyed by type code: label, description and example expressions.
     */
    const TYPES = [
        'differentiate' => [
            'label' => 'differentiate',
            'desc' => 'a polynomial in x to differentiate, using the integer parameter a (for example '
                . '(x-a)^3 or a*x^3 - x)',
            'examples' => ['a*x^3 - x', '(x-a)^3', 'x^4 + a*x^2'],
        ],
        'integrate' => [
            'label' => 'find an antiderivative of',
            'desc' => 'a polynomial in x with an elementary antiderivative, using the integer parameter a '
                . '(for example a*x^2 + x); never a fraction, root or transcendental function',
            'examples' => ['a*x^2 + x', 'x^3 - a*x', '(x + a)^2'],
        ],
    ];

    /** @var array<string, string> Per-difficulty shaping guidance appended to the user prompt. */
    const DIFFICULTY = [
        'easy' => 'Keep it a low-degree polynomial (degree 2) with one or two terms.',
        'medium' => 'Use a degree-3 polynomial with the integer parameter a in one coefficient.',
        'hard' => 'Use a degree 3 to 4 polynomial that combines two or three terms with the parameter a.',
    ];

    /** @var string The shared, backend-agnostic system preamble (minimal-JSON contract). */
    const SYSTEM_BASE =
        "You are a mathematics question author for a STACK quiz.\n" .
        "Output ONLY one JSON object of the form {\"expr\": \"<Maxima expression>\"} and nothing else: " .
        "no prose, no markdown, no code fences and no solution.\n" .
        "Use Maxima syntax: ^ for powers and * for multiplication. " .
        "Use only the variable x and the integer parameters a and b.";

    /**
     * Whether a type produces an AI proposal prompt (only the two expr-driven types do).
     *
     * @param string $type The question type.
     * @return bool True if the type consumes an AI-supplied expression.
     */
    public static function supports(string $type): bool {
        return isset(self::TYPES[$type]);
    }

    /**
     * The few-shot block for the system prompt: one worked example for the type.
     *
     * @param string $type The question type.
     * @return string The few-shot block, or '' for an unsupported type.
     */
    public static function fewshot(string $type): string {
        if (!isset(self::TYPES[$type])) {
            return '';
        }
        $example = self::TYPES[$type]['examples'][0];
        return "\nExample of a good answer (invent your own, do not copy it): {\"expr\": \"" . $example . "\"}.";
    }

    /**
     * The compact system prompt (small / on-device models): the base contract plus one few-shot example.
     *
     * @param string $type The question type.
     * @param string $difficulty The requested difficulty.
     * @return array The system and user messages, keyed 'system' and 'user'.
     */
    public static function compact(string $type, string $difficulty): array {
        return self::messages($type, $difficulty, [], [], self::TIER_COMPACT);
    }

    /**
     * The verbose system prompt (capable cloud models): the base contract, RIGHT/WRONG examples and a
     * few-shot example.
     *
     * @param string $type The question type.
     * @param string $difficulty The requested difficulty.
     * @return array The system and user messages, keyed 'system' and 'user'.
     */
    public static function verbose(string $type, string $difficulty): array {
        return self::messages($type, $difficulty, [], [], self::TIER_VERBOSE);
    }

    /**
     * Build the system + user messages for a proposal, the single builder every backend consumes.
     *
     * @param string $type The question type.
     * @param string $difficulty The requested difficulty.
     * @param string[] $avoid Short retry hints (recent Maxima failures for this type) to steer away from.
     * @param string[] $used Expressions already accepted this batch (an anti-duplication list).
     * @param string $tier One of TIER_COMPACT or TIER_VERBOSE.
     * @return array The messages keyed 'system' and 'user' ('' / '' for an unsupported type).
     */
    public static function messages(
        string $type,
        string $difficulty,
        array $avoid = [],
        array $used = [],
        string $tier = self::TIER_COMPACT
    ): array {
        if (!isset(self::TYPES[$type])) {
            return ['system' => '', 'user' => ''];
        }
        $meta = self::TYPES[$type];
        $system = self::SYSTEM_BASE;
        if ($tier === self::TIER_VERBOSE) {
            $ex = $meta['examples'][0];
            $system .= "\nRIGHT: {\"expr\": \"" . $ex . "\"} is a single Maxima expression in x."
                . "\nWRONG: {\"expr\": \"\$a x^3\$\"} uses LaTeX or dollar signs."
                . "\nWRONG: replying \"The expression is a*x^3.\" is prose instead of JSON.";
        }
        $system .= self::fewshot($type);

        $guidance = self::DIFFICULTY[$difficulty] ?? self::DIFFICULTY['easy'];
        $user = "Produce one JSON object {\"expr\": \"...\"} for a {$difficulty} \"{$meta['label']}\" question.\n"
            . "The expression must be {$meta['desc']}.\n"
            . $guidance;

        // AVOID block: the targeted guidance from recent Maxima failures for this type.
        $avoid = array_values(array_filter(array_map('trim', $avoid), 'strlen'));
        if ($avoid) {
            $user .= "\nAvoid what made earlier attempts fail:\n- " . implode("\n- ", array_slice($avoid, -4));
        }

        // Anti-duplication list: the expressions already accepted in this batch.
        $used = array_values(array_filter(array_map('trim', $used), 'strlen'));
        if ($used) {
            $user .= "\nDo not reuse any of these expressions: " . implode(', ', array_slice($used, -8)) . '.';
        }

        return ['system' => $system, 'user' => $user];
    }
}
