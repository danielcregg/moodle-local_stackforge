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

    /**
     * Types whose template actually CONSUMES an AI-supplied expr. The other six are fully parameterised
     * by their own random variables, so we never spend an AI call on them.
     *
     * @var array<string, string>
     */
    const EXPR_TYPES = [
        'differentiate' => 'a polynomial in x to differentiate, using integer parameter a, e.g. (x-a)^3 or a*x^3 - x',
        'integrate'     => 'a simple polynomial in x to integrate, using parameter a, e.g. a*x^2 + x',
    ];

    /** @var string The author system prompt. */
    const SYSTEM = 'You are a mathematics question author. Output only valid JSON.';

    /**
     * Whether a type consumes an AI-supplied expression.
     *
     * @param string $type The question type.
     * @return bool True if the type uses an expr.
     */
    public static function uses_expr(string $type): bool {
        return isset(self::EXPR_TYPES[$type]);
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
     * Propose a safe source expression for an expr-driven type.
     *
     * @param string $type The question type.
     * @param string $difficulty The requested difficulty.
     * @return string|null A grammar-safe expression, or null if unavailable/unusable.
     */
    public static function propose_expr(string $type, string $difficulty): ?string {
        if (!isset(self::EXPR_TYPES[$type]) || !self::configured()) {
            return null;
        }
        $providerid = (string) get_config('local_stackforge', 'ai_provider');
        $model = (string) get_config('local_stackforge', 'ai_model');
        $key = (string) get_config('local_stackforge', 'ai_key');
        $p = self::PROVIDERS[$providerid];
        $prompt = self::build_prompt($type, $difficulty);

        try {
            switch ($p['kind']) {
                case 'openai':
                    $text = self::call_openai($p['endpoint'], $key, $model, self::SYSTEM, $prompt);
                    break;
                case 'gemini':
                    $text = self::call_gemini($p['endpoint'], $key, $model, self::SYSTEM, $prompt);
                    break;
                case 'anthropic':
                    $text = self::call_anthropic($p['endpoint'], $key, $model, self::SYSTEM, $prompt);
                    break;
                default:
                    return null;
            }
        } catch (\Throwable $e) {
            debugging('local_stackforge AI proposal failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }

        $json = self::extract_json($text);
        if (!is_array($json) || !isset($json['expr']) || !is_string($json['expr']) || trim($json['expr']) === '') {
            return null;
        }
        $expr = normalize::tidy_expr($json['expr']);
        if (!normalize::looks_safe_expr($expr)) {
            return null;
        }
        return $expr;
    }

    /**
     * Build the expression-proposal user prompt (mirrors docs/js/ai.js buildSlotPrompt).
     *
     * @param string $type The question type.
     * @param string $difficulty The requested difficulty.
     * @return string The prompt.
     */
    private static function build_prompt(string $type, string $difficulty): string {
        $desc = self::EXPR_TYPES[$type];
        $typename = str_replace('_', ' ', $type);
        return "Produce ONE JSON object of the form {\"expr\": \"<Maxima expression>\"} for a {$difficulty} "
            . "\"{$typename}\" question. The expression must be {$desc}. "
            . 'Use only the variable x and integer parameters a (and b if needed) — do NOT introduce any '
            . 'other variables. Use Maxima syntax: ^ for powers, * for multiplication, and functions like '
            . 'expand(...). Output ONLY the JSON object, nothing else.';
    }

    /**
     * Extract the first JSON object from a model response (strips markdown fences/prose).
     *
     * @param string $text The model output.
     * @return array|null The decoded object, or null.
     */
    private static function extract_json(string $text): ?array {
        $s = trim($text);
        $i = strpos($s, '{');
        $j = strrpos($s, '}');
        if ($i !== false && $j !== false && $j > $i) {
            $s = substr($s, $i, $j - $i + 1);
        }
        $data = json_decode($s, true);
        return is_array($data) ? $data : null;
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
        curl_close($ch);
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
