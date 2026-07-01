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
 * Admin settings: generation mode (in-process vs external), the in-process AI provider, and the
 * external generation service.
 *
 * In-process mode needs no backend: it validates against Moodle's own qtype_stack + Maxima and only
 * needs an AI key (for the differentiate/integrate types). The external service path is retained as a
 * fallback. Everything defaults to empty/auto: the plugin only ever contacts an AI host you configure.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_stackforge', get_string('pluginname', 'local_stackforge'));
    $ADMIN->add('localplugins', $settings);

    // Generation mode.
    $settings->add(new admin_setting_configselect(
        'local_stackforge/mode',
        get_string('mode', 'local_stackforge'),
        get_string('mode_desc', 'local_stackforge'),
        'auto',
        [
            'auto'      => get_string('mode_auto', 'local_stackforge'),
            'inprocess' => get_string('mode_inprocess', 'local_stackforge'),
            'external'  => get_string('mode_external', 'local_stackforge'),
        ]
    ));

    // In-process AI: validates locally; only needs an AI key to draft expressions (differentiate/integrate).
    $settings->add(new admin_setting_heading(
        'local_stackforge/aiheading',
        get_string('aiheading', 'local_stackforge'),
        get_string('aiheading_desc', 'local_stackforge')
    ));

    // Which AI backend drafts the expression: Moodle's built-in core AI (reuses a site-configured
    // provider/key, with Moodle's AI policy + logging) or this plugin's own provider/key below. Auto
    // prefers core when available, else this plugin's own provider. Either way the oracle validates the
    // result, and the deterministic template default is used if no AI is available.
    $settings->add(new admin_setting_configselect(
        'local_stackforge/aibackend',
        get_string('aibackend', 'local_stackforge'),
        get_string('aibackend_desc', 'local_stackforge'),
        'auto',
        [
            'auto'     => get_string('aibackend_auto', 'local_stackforge'),
            'core'     => get_string('aibackend_core', 'local_stackforge'),
            'own'      => get_string('aibackend_own', 'local_stackforge'),
            'ondevice' => get_string('aibackend_ondevice', 'local_stackforge'),
        ]
    ));

    // On-device (in-browser WebLLM) model. Generation has no answer to leak (the server oracle validates
    // every proposal), so unlike the hinter the model is configurable; the default is a small
    // coder/instruct model good at emitting the minimal JSON contract. Only used when the backend
    // resolves to on-device. Every option is a real WebLLM prebuilt model id.
    $settings->add(new admin_setting_configselect(
        'local_stackforge/ondevicemodel',
        get_string('ondevicemodel', 'local_stackforge'),
        get_string('ondevicemodel_desc', 'local_stackforge'),
        'Qwen2.5-Coder-1.5B-Instruct-q4f16_1-MLC',
        [
            'Qwen2.5-Coder-1.5B-Instruct-q4f16_1-MLC' => 'Qwen2.5-Coder 1.5B Instruct (default)',
            'Qwen2.5-Coder-0.5B-Instruct-q4f16_1-MLC' => 'Qwen2.5-Coder 0.5B Instruct (smallest, fastest)',
            'Qwen2.5-Coder-3B-Instruct-q4f16_1-MLC'   => 'Qwen2.5-Coder 3B Instruct (larger, slower)',
            'Llama-3.2-3B-Instruct-q4f16_1-MLC'       => 'Llama 3.2 3B Instruct',
            'gemma-2-2b-it-q4f16_1-MLC'               => 'Gemma 2 2B Instruct (cache-shared with the hinter)',
        ]
    ));

    // Let the browser remember, per question type, the guidance from recent Maxima failures so a later
    // batch avoids the same mistakes (stored only in the author's browser localStorage).
    $settings->add(new admin_setting_configcheckbox(
        'local_stackforge/errormemory',
        get_string('errormemory', 'local_stackforge'),
        get_string('errormemory_desc', 'local_stackforge'),
        1
    ));

    $providers = [
        ''         => get_string('ai_provider_none', 'local_stackforge'),
        'openai'   => 'OpenAI',
        'groq'     => 'Groq',
        'deepseek' => 'DeepSeek',
        'mistral'  => 'Mistral',
        'cerebras' => 'Cerebras',
        'zenmux'   => 'ZenMux',
        'claude'   => 'Anthropic (Claude)',
        'gemini'   => 'Google (Gemini)',
    ];
    $settings->add(new admin_setting_configselect(
        'local_stackforge/ai_provider',
        get_string('ai_provider', 'local_stackforge'),
        get_string('ai_provider_desc', 'local_stackforge'),
        '',
        $providers
    ));
    $settings->add(new admin_setting_configtext(
        'local_stackforge/ai_model',
        get_string('ai_model', 'local_stackforge'),
        get_string('ai_model_desc', 'local_stackforge'),
        '',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_stackforge/ai_key',
        get_string('ai_key', 'local_stackforge'),
        get_string('ai_key_desc', 'local_stackforge'),
        ''
    ));

    // A link to run a one-question in-process smoke test (build, validate, delete). If the installed
    // qtype_stack is newer than the version the in-process path was verified against, prepend a heads-up
    // to re-run that smoke test.
    $smokeurl = new moodle_url('/local/stackforge/smoke.php');
    $smokedesc = get_string('smoke_desc', 'local_stackforge', $smokeurl->out());
    $drift = \local_stackforge\local\inprocess_validator::version_warning();
    if ($drift !== null) {
        $smokedesc = html_writer::div(
            get_string('qtypeversionwarning', 'local_stackforge', (object) $drift),
            'alert alert-warning'
        ) . $smokedesc;
    }
    $settings->add(new admin_setting_heading(
        'local_stackforge/smokeheading',
        get_string('smokeheading', 'local_stackforge'),
        $smokedesc
    ));

    // External generation service (fallback path).
    $settings->add(new admin_setting_heading(
        'local_stackforge/externalheading',
        get_string('externalheading', 'local_stackforge'),
        get_string('externalheading_desc', 'local_stackforge')
    ));

    // PARAM_RAW_TRIMMED (not PARAM_URL): the endpoint may legitimately be an internal host such as
    // http://generate:8092, which PARAM_URL would strip. The URL is validated where it is used
    // (generator::base_url(): http/https scheme, host required, no embedded credentials).
    $settings->add(new admin_setting_configtext(
        'local_stackforge/serviceurl',
        get_string('serviceurl', 'local_stackforge'),
        get_string('serviceurl_desc', 'local_stackforge'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_stackforge/apitoken',
        get_string('apitoken', 'local_stackforge'),
        get_string('apitoken_desc', 'local_stackforge'),
        ''
    ));
}
