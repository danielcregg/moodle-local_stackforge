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
 * Server-side AI client: proposes ONE source expression for an expr-driven question type.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\local;

/**
 * The AI is never trusted to assert a correct answer. It only proposes a source expression within a
 * safe grammar; the template's own Maxima computes the teacher answer and the in-process oracle proves
 * it. A wrong/odd expression simply fails validation and is retried (then the template default is used).
 * The key is held server-side and never reaches the browser.
 */
class ai_client {
    /** @var array OpenAI-compatible / gemini / anthropic endpoints, keyed by provider id. */
    const PROVIDERS = [
        'openai'   => ['kind' => 'openai', 'endpoint' => 'https://api.openai.com/v1/chat/completions'],
        'groq'     => ['kind' => 'openai', 'endpoint' => 'https://api.groq.com/openai/v1/chat/completions'],
        'deepseek' => ['kind' => 'openai', 'endpoint' => 'https://api.deepseek.com/chat/completions'],
        'mistral'  => ['kind' => 'openai', 'endpoint' => 'https://api.mistral.ai/v1/chat/completions'],
        'cerebras' => ['kind' => 'openai', 'endpoint' => 'https://api.cerebras.ai/v1/chat/completions'],
        'zenmux'   => ['kind' => 'openai', 'endpoint' => 'https://zenmux.ai/api/v1/chat/completions'],
        'claude'   => ['kind' => 'anthropic', 'endpoint' => 'https://api.anthropic.com/v1/messages'],
        'gemini'   => ['kind' => 'gemini', 'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/'],
    ];

    /** @var string The default on-device (WebLLM) model when the ondevicemodel setting is unset. */
    const DEFAULT_ONDEVICE_MODEL = 'gemma-2-2b-it-q4f16_1-MLC';

    /**
     * Whether a type consumes an AI-supplied expression (only the two expr-driven types do).
     *
     * @param string $type The question type.
     * @return bool True if the type uses an expr.
     */
    public static function uses_expr(string $type): bool {
        return prompt_rules::supports($type);
    }

    /**
     * Whether the AI is fully configured (provider + model + key).
     *
     * @return bool True if a draft expression can be requested.
     */
    public static function configured(): bool {
        $provider = (string) get_config('local_stackforge', 'ai_provider');
        $model = (string) get_config('local_stackforge', 'ai_model');
        $key = (string) get_config('local_stackforge', 'ai_key');
        return $provider !== '' && isset(self::PROVIDERS[$provider]) && $model !== '' && $key !== '';
    }

    /**
     * Whether this plugin's own provider is fully configured (an alias of configured(), mirroring the
     * hinter's resolve chain).
     *
     * @return bool True if the own-provider path can run.
     */
    public static function own_configured(): bool {
        return self::configured();
    }

    /**
     * The on-device (in-browser WebLLM) model id used when aibackend is 'ondevice'.
     *
     * Unlike the hinter (where answer-leak forces a fixed 0%-leak model), generation has no answer to
     * leak: the model only proposes an expression and the SERVER oracle validates it. So the model is
     * configurable.
     *
     * A Janus A100 eval (valid-JSON + oracle-pass rate; 5 WebLLM-deployable candidates x 2 types x 3
     * difficulties x 3 temperatures) picked gemma-2-2b: highest oracle-pass rate (96.7% of all trials,
     * 98.9% of valid-JSON), because the coder models proposed expressions outside the bounded allow-list
     * far more often (gate-rejects: gemma-2-2b 2 vs Qwen2.5-Coder-1.5B 20). It also shares the hinter's
     * model cache. See research/data/gen_model_eval.json.
     *
     * @return string A WebLLM prebuilt model id.
     */
    public static function ondevice_model(): string {
        $model = trim((string) get_config('local_stackforge', 'ondevicemodel'));
        return $model !== '' ? $model : self::DEFAULT_ONDEVICE_MODEL;
    }

    /**
     * Resolve which AI backend drafts the expression: 'core' (Moodle's AI subsystem), 'own' (this
     * plugin's provider/key) or 'ondevice' (a model in the author's browser).
     *
     * In 'auto' the plugin needs no configuration: core AI when the site has it, else this plugin's own
     * provider when a key is set, else the zero-config on-device model. Explicit choices are honoured.
     *
     * @param \context|null $context The request context (core needs one).
     * @return string 'core', 'own' or 'ondevice'.
     */
    public static function resolve_backend(?\context $context): string {
        $backend = (string) get_config('local_stackforge', 'aibackend');
        if (in_array($backend, ['core', 'own', 'ondevice'], true)) {
            return $backend;
        }
        // Auto (default): core when available, else this plugin's own provider, else on-device.
        if ($context !== null && core_ai::available()) {
            return 'core';
        }
        return self::own_configured() ? 'own' : 'ondevice';
    }

    /**
     * Whether a SERVER-side AI drafting backend is usable (so the server pipeline tries AI before the
     * template default). The on-device backend drafts in the browser, so it is never server-usable — the
     * server job path falls back to the deterministic template default, and the browser drives on-device.
     *
     * @param \context|null $context The request context.
     * @return bool True if core AI is available, or this plugin's own provider is configured.
     */
    public static function ai_available(?\context $context): bool {
        $backend = self::resolve_backend($context);
        if ($backend === 'core') {
            return core_ai::available();
        }
        if ($backend === 'own') {
            return self::configured();
        }
        return false;
    }

    /**
     * The compact system + user messages for a proposal, for the on-device browser backend to run
     * locally. Delegates to prompt_rules so PRE is identical to the server path. There is no answer in
     * the payload: the model only proposes an expression and the server oracle validates it.
     *
     * @param string $type The question type.
     * @param string $difficulty The requested difficulty.
     * @param string[] $avoid Recent retry hints for this type (an AVOID block).
     * @param string[] $used Expressions already accepted this batch (anti-duplication).
     * @return array The messages keyed 'system' and 'user' ('' / '' for a non-expr type).
     */
    public static function build_seed_messages(
        string $type,
        string $difficulty,
        array $avoid = [],
        array $used = []
    ): array {
        return prompt_rules::messages($type, $difficulty, $avoid, $used, prompt_rules::TIER_COMPACT);
    }

    /**
     * Propose a safe source expression for an expr-driven type.
     *
     * The AI only proposes an expression; the oracle still validates it, and the template default is
     * used whenever this returns null. With the core backend, an unaccepted AI policy simply yields null
     * (no exception): generation continues with the deterministic template default rather than routing
     * the request to a different provider.
     *
     * @param string $type The question type.
     * @param string $difficulty The requested difficulty.
     * @param \context|null $context The request context (for the core AI backend).
     * @param int $userid The requesting user id (for the core AI policy check; defaults to current user).
     * @param string[] $avoid Recent retry hints for this type (fed into the prompt's AVOID block).
     * @return string|null A grammar-safe expression, or null if unavailable/unusable.
     */
    public static function propose_expr(
        string $type,
        string $difficulty,
        ?\context $context = null,
        int $userid = 0,
        array $avoid = []
    ): ?string {
        if (!prompt_rules::supports($type)) {
            return null;
        }
        $backend = self::resolve_backend($context);
        // On-device drafting happens in the author's browser, never here on the server.
        if ($backend === 'ondevice') {
            return null;
        }
        // The server path uses the verbose tier (capable cloud models); the AVOID block carries any
        // targeted guidance from a previous failed attempt for this type.
        $messages = prompt_rules::messages($type, $difficulty, $avoid, [], prompt_rules::TIER_VERBOSE);
        $system = $messages['system'];
        $prompt = $messages['user'];
        $text = null;

        if ($backend === 'core') {
            if ($userid <= 0) {
                $userid = (int) ($GLOBALS['USER']->id ?? 0);
            }
            // Core needs the AI policy accepted; if not, skip AI and let the template default apply.
            if (!core_ai::available() || !core_ai::policy_accepted($userid)) {
                return null;
            }
            $text = core_ai::generate_text($context, $userid, $system . "\n\n" . $prompt);
        } else {
            if (!self::configured()) {
                return null;
            }
            $providerid = (string) get_config('local_stackforge', 'ai_provider');
            $model = (string) get_config('local_stackforge', 'ai_model');
            $key = (string) get_config('local_stackforge', 'ai_key');
            $p = self::PROVIDERS[$providerid];
            try {
                switch ($p['kind']) {
                    case 'openai':
                        $text = self::call_openai($p['endpoint'], $key, $model, $system, $prompt);
                        break;
                    case 'gemini':
                        $text = self::call_gemini($p['endpoint'], $key, $model, $system, $prompt);
                        break;
                    case 'anthropic':
                        $text = self::call_anthropic($p['endpoint'], $key, $model, $system, $prompt);
                        break;
                    default:
                        return null;
                }
            } catch (\Throwable $e) {
                debugging('local_stackforge AI proposal failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                return null;
            }
        }

        if ($text === null || trim($text) === '') {
            return null;
        }
        $json = normalize::extract_json($text);
        if (!is_array($json) || !isset($json['expr']) || !is_string($json['expr']) || trim($json['expr']) === '') {
            return null;
        }
        // Deterministic pre-CAS repair, then the unchanged allow-list gate decides admissibility.
        [$expr] = normalize::repair_expr($json['expr']);
        if (!normalize::looks_safe_expr($expr)) {
            return null;
        }
        return $expr;
    }

    /**
     * Perform a JSON HTTP POST and return the decoded response.
     *
     * @param string $url The endpoint URL.
     * @param array $headers HTTP headers.
     * @param array $body The request body (JSON-encoded before sending).
     * @return array The decoded JSON response.
     * @throws \moodle_exception On transport error, non-2xx status, or invalid JSON.
     */
    private static function http(string $url, array $headers, array $body): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Container DNS returns IPv6-first but has no IPv6 egress; force IPv4.
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        // No curl_close(): it is deprecated in PHP 8+ (a no-op) — the handle is freed when $ch goes out of scope.
        if ($resp === false) {
            throw new \moodle_exception('aifailed', 'local_stackforge', '', $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new \moodle_exception('aifailed', 'local_stackforge', '', $code . ': ' . substr((string) $resp, 0, 200));
        }
        $data = json_decode($resp, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('aifailed', 'local_stackforge', '', 'invalid JSON');
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Call an OpenAI-compatible chat-completions endpoint.
     *
     * @param string $endpoint The endpoint URL.
     * @param string $key The API key.
     * @param string $model The model id.
     * @param string $system The system prompt.
     * @param string $user The user prompt.
     * @return string The model text.
     */
    private static function call_openai(string $endpoint, string $key, string $model, string $system, string $user): string {
        $j = self::http($endpoint, ['Content-Type: application/json', 'Authorization: Bearer ' . $key], [
            'model' => $model, 'temperature' => 0.4,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        ]);
        return (string) ($j['choices'][0]['message']['content'] ?? '');
    }

    /**
     * Call the Google Gemini generateContent endpoint.
     *
     * @param string $endpoint The endpoint base URL.
     * @param string $key The API key.
     * @param string $model The model id.
     * @param string $system The system prompt.
     * @param string $user The user prompt.
     * @return string The model text.
     */
    private static function call_gemini(string $endpoint, string $key, string $model, string $system, string $user): string {
        $url = $endpoint . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
        $j = self::http($url, ['Content-Type: application/json'], [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig' => ['temperature' => 0.4],
        ]);
        $parts = $j['candidates'][0]['content']['parts'] ?? [];
        $text = '';
        foreach ($parts as $part) {
            $text .= $part['text'] ?? '';
        }
        return $text;
    }

    /**
     * Call the Anthropic Claude messages endpoint.
     *
     * @param string $endpoint The endpoint URL.
     * @param string $key The API key.
     * @param string $model The model id.
     * @param string $system The system prompt.
     * @param string $user The user prompt.
     * @return string The model text.
     */
    private static function call_anthropic(string $endpoint, string $key, string $model, string $system, string $user): string {
        $j = self::http(
            $endpoint,
            ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
            [
                'model' => $model,
                'max_tokens' => 512,
                'temperature' => 0.4,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
            ]
        );
        $blocks = $j['content'] ?? [];
        $text = '';
        foreach ($blocks as $b) {
            $text .= $b['text'] ?? '';
        }
        return $text;
    }
}
