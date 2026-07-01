# Changelog

All notable changes to **local_stackforge** are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and this project adheres to
[Semantic Versioning](https://semver.org/).

## [1.2.0-beta] — 2026-07-01

Adds an optional **on-device (in-browser) AI backend** and a shared **max-squeeze pre/post pipeline**.
Additive and inert by default: existing sites are unchanged unless the new backend is selected.

### Added
- **On-device AI backend** (`AI backend` setting: adds `On-device`). The author's browser drafts the
  source expression for the differentiate and integrate types with a small **WebLLM/WebGPU** model — no
  API key and no external AI provider. The browser posts only `{type, difficulty, expr}` to the plugin's
  own endpoint; the **server still runs the oracle** (scratch import → per-seed instantiation → runtime
  CAS-error rejection → `test_question()`) and imports only what passes. The browser never sends XML, and
  because the template's Maxima computes the answer server-side there is nothing to leak.
- New **On-device model** setting (a curated set of real WebLLM prebuilt ids; default a small
  coder/instruct model) and a **Remember on-device failures** toggle (per-type browser-side self-improvement).
- A shared, backend-agnostic **pre/post pipeline** that lifts every backend's valid-expression rate:
  a compact/verbose minimal-JSON prompt builder with a worked few-shot example and difficulty guidance
  (`classes/local/prompt_rules.php`); deterministic pre-CAS expression repair feeding the unchanged
  allow-list gate (`normalize::repair_expr`, plus a hardened `normalize::extract_json`); a Maxima-reason
  → retry-hint catalog (`classes/local/error_hints.php`); and **generate-until-valid** batch generation
  (discard an AI miss and retry up to a 2× cap, with the validated template default still guaranteeing
  the final count).

### Changed
- The server AI drafting path now routes through the shared prompt builder and threads each Maxima
  failure into the next attempt's guidance.
- Privacy: the on-device backend sends nothing to any external AI provider; a one-time model download
  from a public CDN (no personal data) is disclosed.

### Security
- The on-device endpoint (`ajax.php`) is capability- and sesskey-gated (`local/stackforge:generate` +
  `moodle/question:add`), imports only into a category that belongs to the course context, and **never**
  accepts XML from the browser — the server builds the XML and runs the oracle on every candidate.

## [1.1.0-beta] — 2026-06-24

Adds a **zero-backend, in-process** generation mode, live-tested on Moodle 4.5.12.

### Added
- **In-process mode**: draft and validate STACK questions against this site's own `qtype_stack` +
  Maxima — no external service required. Install the plugin, set an AI provider/model/key, and go.
- **Hybrid AI backend** (`AI backend` setting: auto / core / own): draft the source expression via
  Moodle's built-in core AI (reusing a site-configured provider, with Moodle's AI policy + logging) or
  this plugin's own key. Auto prefers core when available, else the own provider; the deterministic
  template default is used if no AI is available. The AI still only proposes an expression — the oracle
  validates it — and existing own-configured sites are migrated to `own` (never silently switched).
- New **Generation mode** setting (`auto` / `inprocess` / `external`). `auto` keeps an already-configured
  external service (so existing sites are never silently switched), otherwise runs in-process.
- The in-process **oracle**: instantiates each draft across every deployed seed, rejects any runtime
  CAS error (a grammar-valid but non-elementary integrand can no longer slip through), bakes the
  terminal answer notes, and proves the exported question passes its own question-tests via Moodle's
  `test_question()`.
- Asynchronous generation via an adhoc task + a job table, with live progress on the course page; a
  scheduled task cleans up any stale scratch validation category.
- An admin **in-process smoke-test** page (build → validate → delete one known-good question).
- A **version-drift warning** on the settings and smoke-test pages when the installed `qtype_stack` is
  newer than the version in-process mode was verified against, prompting a re-run of the smoke test.

### Changed
- The Privacy API provider now describes, exports and deletes the generation-job records the plugin
  stores (it is no longer a null provider).
- The course page queues a background job instead of generating synchronously.

### Security
- The AI key is stored server-side and never sent to the browser; the AI only ever proposes a source
  expression, gated by an allow-list grammar before it can reach Maxima.

## [1.0.0-beta] — 2026-06-23

First public release, prepared for submission to the Moodle Plugins directory.

### Added
- Generate AI-drafted, oracle-validated STACK questions directly into a course question bank.
- **Build RL-sequenced quiz**: generate a curriculum-ordered question set and create an adaptive quiz.
- Course navigation entry, gated by the `local/stackforge:generate` capability.
- Full Privacy API implementation (null provider — the plugin stores no personal data).
- GPL v3 file headers, `moodle-plugin-ci` workflow, and per-plugin documentation.

### Security
- The external service URL and token both **default to empty**; the plugin contacts nothing until an
  administrator configures a trusted endpoint.
- The configured endpoint is validated (http/https only, host required, no embedded credentials);
  redirects are disabled and request protocols are pinned to HTTP/HTTPS.
- Adding questions and creating a quiz additionally require the core `moodle/question:add` and
  `moodle/course:manageactivities` capabilities.
