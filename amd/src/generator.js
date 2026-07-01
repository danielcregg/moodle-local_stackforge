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
 * On-device (in-browser WebLLM) STACK question generator driver.
 *
 * The model runs entirely in the author's browser and only proposes a source expression; every candidate
 * is posted to this plugin's own endpoint where the SERVER oracle (Maxima) validates it and imports it.
 * There is no answer to leak: the template computes it server-side.
 *
 * @module     local_stackforge/generator
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Cache one WebLLM module load and one engine per model id across the page (download once).
let webllmModulePromise = null;
const enginePromises = {};

/**
 * Load the WebLLM ES module from the CDN, once.
 *
 * We must NOT write a native import(url) expression in this AMD source: Moodle's AMD build (Babel with
 * the system-import-transformer) rewrites import() into a RequireJS require(), which cannot load an ES
 * module from a CDN and fails. Instead we run the dynamic import inside an injected module script, where
 * it is plain text the build never transforms, and bridge the result back through a promise.
 *
 * @param {string} url The WebLLM ES module URL (a fixed admin-side constant, never user input).
 * @return {Promise} A promise resolving to the WebLLM module namespace.
 */
const loadWebllm = (url) => {
    if (!webllmModulePromise) {
        webllmModulePromise = new Promise((resolve, reject) => {
            window.stackforgeWebllmResolve = resolve;
            window.stackforgeWebllmReject = reject;
            const script = document.createElement('script');
            script.type = 'module';
            script.textContent = 'import(' + JSON.stringify(url) + ').then('
                + 'function(m){window.stackforgeWebllmResolve(m);},'
                + 'function(e){window.stackforgeWebllmReject(e);});';
            document.head.appendChild(script);
        });
    }
    return webllmModulePromise;
};

/**
 * Lazily create the WebLLM engine for a model, reporting one-time download progress.
 *
 * @param {object} config The generator configuration passed from PHP.
 * @param {string} model The WebLLM model id to run.
 * @param {Function} onProgress The WebLLM init-progress callback.
 * @return {Promise} A promise resolving to the ready WebLLM engine.
 */
const getEngine = (config, model, onProgress) => {
    return loadWebllm(config.webllmurl).then((webllm) => {
        if (!enginePromises[model]) {
            enginePromises[model] = webllm.CreateMLCEngine(model, {initProgressCallback: onProgress});
        }
        return enginePromises[model];
    });
};

/**
 * Set the status panel text.
 *
 * @param {HTMLElement} panel The status element.
 * @param {string} text The message to show.
 * @return {void}
 */
const setStatus = (panel, text) => {
    if (panel) {
        panel.textContent = text;
    }
};

/**
 * Extract a source expression from raw model output.
 *
 * Mirrors the server normalize::extract_json + repair_expr just enough to produce a clean candidate:
 * the server repairs and gates the expression authoritatively, so this is best-effort only.
 *
 * @param {string} raw The raw model output.
 * @return {string} The extracted expression, or '' if none was found.
 */
const extractExpr = (raw) => {
    let text = String(raw || '');
    // Drop a reasoning-model thinking block and markdown code fences.
    text = text.replace(/<think>[\s\S]*?<\/think>/gi, '');
    text = text.replace(/```[a-zA-Z0-9]*/g, '').replace(/```/g, '');

    let expr = '';
    const match = text.match(/\{[\s\S]*?\}/);
    if (match) {
        try {
            const obj = JSON.parse(match[0].replace(/,\s*([}\]])/g, '$1'));
            if (obj && typeof obj.expr === 'string') {
                expr = obj.expr;
            }
        } catch (e) {
            expr = '';
        }
    }
    if (!expr) {
        // Fall back to a loose "expr": "..." match if strict JSON parsing failed.
        const loose = text.match(/"expr"\s*:\s*"([^"]+)"/);
        if (loose) {
            expr = loose[1];
        }
    }
    // Light cleanup; the server does the authoritative repair and allow-list gating.
    expr = expr.replace(/\$+/g, '').replace(/\\[()[\]]/g, '').replace(/`/g, '').trim();
    expr = expr.replace(/[;$]+$/, '').trim();
    return expr;
};

/**
 * Read the per-type AVOID memory from localStorage (the browser's self-improvement across batches).
 *
 * @param {object} config The generator configuration.
 * @param {string} type The question type.
 * @return {string[]} The remembered retry hints (possibly empty).
 */
const readAvoidMemory = (config, type) => {
    if (!config.errormemory) {
        return [];
    }
    try {
        const raw = window.localStorage.getItem('local_stackforge_avoid_' + type);
        const parsed = raw ? JSON.parse(raw) : [];
        return Array.isArray(parsed) ? parsed.slice(-4) : [];
    } catch (e) {
        return [];
    }
};

/**
 * Persist the per-type AVOID memory to localStorage.
 *
 * @param {object} config The generator configuration.
 * @param {string} type The question type.
 * @param {string[]} hints The retry hints to remember.
 * @return {void}
 */
const writeAvoidMemory = (config, type, hints) => {
    if (!config.errormemory) {
        return;
    }
    try {
        window.localStorage.setItem('local_stackforge_avoid_' + type, JSON.stringify(hints.slice(-6)));
    } catch (e) {
        // Ignore storage failures; the memory is a best-effort optimisation only.
    }
};

/**
 * Build the user prompt for one attempt from the server-supplied base plus the browser-accumulated
 * AVOID and anti-duplication blocks.
 *
 * @param {object} base The base {system, user} messages for this type and difficulty.
 * @param {string[]} avoid The accumulated retry hints.
 * @param {string[]} used The expressions already accepted this batch.
 * @return {string} The assembled user prompt.
 */
const buildUser = (base, avoid, used) => {
    let user = base.user;
    if (avoid.length) {
        user += '\n\nAvoid what made earlier attempts fail:\n- ' + avoid.slice(-4).join('\n- ');
    }
    if (used.length) {
        user += '\n\nDo not reuse any of these expressions: ' + used.slice(-8).join(', ') + '.';
    }
    return user;
};

/**
 * Run the model once and return the raw completion text.
 *
 * @param {object} engine The WebLLM engine.
 * @param {object} base The base {system, user} messages.
 * @param {string[]} avoid The accumulated retry hints.
 * @param {string[]} used The expressions already accepted this batch.
 * @param {number} step The attempt index (ramps the temperature for variety).
 * @return {Promise} A promise resolving to the raw model text.
 */
const runModel = (engine, base, avoid, used, step) => {
    // Fold system and user into one message: some MLC models (for example gemma-2) have no system role.
    const content = base.system + '\n\n' + buildUser(base, avoid, used);
    return engine.chat.completions.create({
        messages: [{role: 'user', content: content}],
        temperature: Math.min(0.4 + 0.15 * step, 0.9),
        // eslint-disable-next-line camelcase
        max_tokens: 160
    }).then((completion) => {
        return completion && completion.choices && completion.choices[0]
            && completion.choices[0].message ? completion.choices[0].message.content : '';
    });
};

/**
 * Post one proposed expression to the server oracle for validation and import.
 *
 * @param {object} config The generator configuration.
 * @param {object} slot The {type, difficulty, expr, category} to validate.
 * @return {Promise} A promise resolving to the JSON response ({ok, name, reason, hint}).
 */
const postValidate = (config, slot) => {
    const body = new URLSearchParams({
        sesskey: config.sesskey,
        courseid: String(config.courseid),
        action: 'validateexpr',
        type: slot.type,
        difficulty: slot.difficulty,
        expr: slot.expr,
        category: String(slot.category)
    });
    return fetch(config.ajaxurl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body.toString()
    }).then((response) => response.json()).catch(() => ({ok: false, reason: ''}));
};

/**
 * Render the progress message.
 *
 * @param {object} config The generator configuration.
 * @param {HTMLElement} panel The status element.
 * @param {number} made The number validated so far.
 * @param {number} total The number requested.
 * @return {void}
 */
const showProgress = (config, panel, made, total) => {
    const strings = config.strings || {};
    const text = (strings.generating || '{made}/{total}')
        .replace('{made}', String(made)).replace('{total}', String(total));
    setStatus(panel, text);
};

/**
 * Run one on-device generate-and-validate batch for the current form selection.
 *
 * @param {object} config The generator configuration.
 * @param {HTMLElement} panel The status element.
 * @return {Promise} A promise that resolves when the batch is complete.
 */
const runBatch = (config, panel) => {
    const strings = config.strings || {};
    const doc = document;
    const type = (doc.getElementById('qtype') || {}).value || 'differentiate';
    const difficulty = (doc.getElementById('difficulty') || {}).value || 'easy';
    const total = parseInt((doc.getElementById('count') || {}).value || '1', 10);
    const category = parseInt((doc.getElementById('category') || {}).value || String(config.categoryid), 10);

    const base = (config.promptrules || {})[type + '|' + difficulty];
    if (!base) {
        // The six non-expr types are template-only: on-device drafting cannot help them.
        setStatus(panel, strings.notexprtype || '');
        return Promise.resolve();
    }
    if (!navigator.gpu) {
        setStatus(panel, strings.nowebgpu || '');
        return Promise.resolve();
    }

    setStatus(panel, strings.loading || '');
    const onProgress = (report) => {
        const pct = Math.round((report && report.progress ? report.progress : 0) * 100);
        setStatus(panel, (strings.download || '{$a}%').replace('{$a}', String(pct)));
    };

    const avoid = readAvoidMemory(config, type);
    const used = [];
    let made = 0;
    let attempts = 0;
    const maxAttempts = Math.max(total, total * 2);

    // The sequential draft->validate loop. Defined at this scope with the engine passed in (rather than
    // inside the getEngine().then() callback) so its promise chain is not nested inside another then()
    // callback, keeping eslint-plugin-promise (no-nesting) satisfied. Mirrors hinter.js's flat-chain style.
    const step = (engine) => {
        if (made >= total || attempts >= maxAttempts) {
            const done = (strings.done || '{made}/{total}')
                .replace('{made}', String(made)).replace('{total}', String(total));
            setStatus(panel, done);
            if (made > 0 && config.bankurl) {
                const link = doc.createElement('a');
                link.href = config.bankurl;
                link.textContent = ' ' + (strings.backtobank || '');
                panel.appendChild(link);
            }
            return Promise.resolve();
        }
        attempts++;
        setStatus(panel, strings.validating || '');
        return runModel(engine, base, avoid, used, attempts)
            .then((raw) => {
                const expr = extractExpr(raw);
                if (!expr) {
                    return null;
                }
                return postValidate(config, {type: type, difficulty: difficulty, expr: expr, category: category});
            })
            .then((res) => {
                if (res && res.ok) {
                    made++;
                    showProgress(config, panel, made, total);
                } else if (res && res.hint) {
                    avoid.push(res.hint);
                    writeAvoidMemory(config, type, avoid);
                }
                return step(engine);
            })
            .catch(() => step(engine));
    };

    return getEngine(config, config.ondevicemodel, onProgress)
        .then((engine) => step(engine))
        .catch(() => {
            setStatus(panel, strings.unavailable || '');
        });
};

/**
 * Entry point: wire the on-device "Generate in my browser" button on the generator course page.
 *
 * @param {object} config The generator configuration passed from PHP.
 * @return {void}
 */
export const init = (config) => {
    if (!config || !config.ajaxurl) {
        return;
    }
    const btn = document.getElementById('local-stackforge-ondevice-btn');
    const panel = document.getElementById('local-stackforge-ondevice-status');
    if (!btn || !panel) {
        return;
    }
    btn.addEventListener('click', () => {
        btn.disabled = true;
        // Return the chain so promise/catch-or-return is satisfied (runBatch already catches internally).
        return runBatch(config, panel).finally(() => {
            btn.disabled = false;
        });
    });
};
