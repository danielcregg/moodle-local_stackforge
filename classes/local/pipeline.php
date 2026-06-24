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
 * Orchestrates generation: pick a mode, propose an expression with AI (retries), validate, fall back.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * Mirrors docs/js/pipeline.js, but mode-aware: in-process (Moodle's own qtype_stack + Maxima) or the
 * external generation service. The AI proposes only a source expression; everything else (templates,
 * grading, the oracle) is deterministic. A wrong expression fails validation and is retried, then the
 * template default is used.
 */
class pipeline {
    /** @var string The external generation-service mode. */
    const MODE_EXTERNAL = 'external';
    /** @var string The in-process (zero-backend) mode. */
    const MODE_INPROCESS = 'inprocess';
    /** @var string Prefer in-process when supported, else fall back to the external service. */
    const MODE_AUTO = 'auto';

    /**
     * The effective mode after resolving the 'auto' setting against what is actually available.
     *
     * @return string One of MODE_INPROCESS or MODE_EXTERNAL.
     */
    public static function resolve_mode(): string {
        $mode = (string) get_config('local_stackforge', 'mode');
        if ($mode === self::MODE_INPROCESS) {
            return self::MODE_INPROCESS;
        }
        if ($mode === self::MODE_EXTERNAL) {
            return self::MODE_EXTERNAL;
        }
        // Auto (the default). Respect an already-configured external backend so an existing site is
        // never silently switched to in-process on upgrade. A fresh install (no service URL) prefers
        // the zero-backend in-process path; if qtype_stack can't support it, generate_inprocess() then
        // surfaces a clear, actionable error.
        if (trim((string) get_config('local_stackforge', 'serviceurl')) !== '') {
            return self::MODE_EXTERNAL;
        }
        return self::MODE_INPROCESS;
    }

    /**
     * Generate and validate one question, dispatching by the resolved mode.
     *
     * @param string $type The question type.
     * @param string $difficulty The requested difficulty.
     * @param int $index The 0-based index within a batch (cycles fallback expressions for variety).
     * @param \context $context The course context.
     * @param \stdClass $course The course.
     * @param \stdClass|null $scratchcat The scratch category (required for in-process; null for external).
     * @param int $attempts Maximum AI attempts before the template fallback.
     * @return array ok|xml|reason|source plus name|type|difficulty on success.
     */
    public static function generate_one(
        string $type,
        string $difficulty,
        int $index,
        \context $context,
        \stdClass $course,
        ?\stdClass $scratchcat,
        int $attempts = 3
    ): array {
        if (self::resolve_mode() === self::MODE_EXTERNAL) {
            return self::generate_external($type, $difficulty);
        }
        return self::generate_inprocess($type, $difficulty, $index, $context, $course, $scratchcat, $attempts);
    }

    /**
     * External path: ask the configured generation service for one validated question.
     *
     * @param string $type The question type.
     * @param string $difficulty The requested difficulty.
     * @return array The result.
     */
    private static function generate_external(string $type, string $difficulty): array {
        try {
            // Generator lives in the parent namespace (local_stackforge), not local_stackforge\local.
            $questions = \local_stackforge\generator::generate($type, $difficulty, 1);
        } catch (\Throwable $e) {
            return ['ok' => false, 'xml' => null, 'reason' => $e->getMessage(), 'source' => 'external'];
        }
        if (empty($questions[0]['xml'])) {
            return ['ok' => false, 'xml' => null, 'reason' => 'service returned no question', 'source' => 'external'];
        }
        return [
            'ok' => true,
            'xml' => $questions[0]['xml'],
            'reason' => '',
            'source' => 'external',
            'name' => $questions[0]['name'] ?? '',
            'type' => $type,
            'difficulty' => $difficulty,
        ];
    }

    /**
     * In-process path: AI proposes the expression (retries), then a validated template fallback.
     *
     * @param string $type The question type.
     * @param string $difficulty The requested difficulty.
     * @param int $index The 0-based batch index (cycles fallback expressions).
     * @param \context $context The course context.
     * @param \stdClass $course The course.
     * @param \stdClass|null $scratchcat The scratch category.
     * @param int $attempts Maximum AI attempts.
     * @return array The result.
     */
    public static function generate_inprocess(
        string $type,
        string $difficulty,
        int $index,
        \context $context,
        \stdClass $course,
        ?\stdClass $scratchcat,
        int $attempts = 3
    ): array {
        if (!template_registry::exists($type)) {
            return ['ok' => false, 'xml' => null, 'reason' => 'unknown type: ' . $type, 'source' => 'inprocess'];
        }
        [$ok, $why] = inprocess_validator::inprocess_supported();
        if (!$ok) {
            return ['ok' => false, 'xml' => null, 'reason' => 'in-process unavailable: ' . $why, 'source' => 'inprocess'];
        }
        if ($scratchcat === null) {
            return ['ok' => false, 'xml' => null, 'reason' => 'no scratch category provided', 'source' => 'inprocess'];
        }

        $lastreason = 'validation failed';

        // AI-proposed expressions first (only for expr-driven types, when configured).
        if (ai_client::uses_expr($type) && ai_client::configured()) {
            for ($i = 0; $i < $attempts; $i++) {
                $expr = ai_client::propose_expr($type, $difficulty);
                if ($expr === null) {
                    continue;
                }
                $q = template_registry::make($type, ['expr' => $expr, 'difficulty' => $difficulty]);
                $res = inprocess_validator::validate($q, $context, $course, $scratchcat);
                if ($res['ok']) {
                    return array_merge($res, ['source' => 'ai', 'attempt' => $i + 1, 'expr' => $expr]);
                }
                $lastreason = $res['reason'];
            }
        }

        // Validated template fallback. Cycle the default expressions for variety on expr-driven types.
        $slot = ['difficulty' => $difficulty];
        if (ai_client::uses_expr($type)) {
            $defaults = template_registry::default_exprs($type);
            if ($defaults) {
                $slot['expr'] = $defaults[$index % count($defaults)];
            }
        }
        $q = template_registry::make($type, $slot);
        $res = inprocess_validator::validate($q, $context, $course, $scratchcat);
        $source = (ai_client::uses_expr($type) && ai_client::configured()) ? 'fallback' : 'template';
        if ($res['ok']) {
            return array_merge($res, ['source' => $source]);
        }
        $reason = ($res['reason'] !== '') ? $res['reason'] : $lastreason;
        return ['ok' => false, 'xml' => null, 'reason' => $reason, 'source' => $source];
    }

    /**
     * Admin smoke test: build one known-good question, validate it in-process, and tear it down.
     * Runs in the site-course context so it needs no course selection.
     *
     * @return array ok|reason|ms|seeds|tests.
     */
    public static function smoke_test(): array {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');

        [$ok, $why] = inprocess_validator::inprocess_supported();
        if (!$ok) {
            return ['ok' => false, 'reason' => $why, 'ms' => 0, 'seeds' => 0, 'tests' => 0];
        }

        $start = microtime(true);
        $course = get_course(SITEID);
        $context = \context_course::instance(SITEID);
        $scratch = scratch_importer::create_category($context, 0);
        try {
            $q = template_registry::make('differentiate', ['difficulty' => 'easy']);
            $res = inprocess_validator::validate($q, $context, $course, $scratch);
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'reason' => $e->getMessage(), 'seeds' => 0, 'tests' => 0];
        } finally {
            scratch_importer::delete_category((int) $scratch->id);
        }

        return [
            'ok' => !empty($res['ok']),
            'reason' => $res['reason'] ?? '',
            'ms' => (int) round((microtime(true) - $start) * 1000),
            'seeds' => $res['seeds'] ?? 0,
            'tests' => $res['tests'] ?? 0,
        ];
    }
}
