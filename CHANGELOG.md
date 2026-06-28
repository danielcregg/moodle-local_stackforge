# Changelog

All notable changes to **local_stackforge** are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and this project adheres to
[Semantic Versioning](https://semver.org/).

## [1.1.0-beta] — 2026-06-24

Adds a **zero-backend, in-process** generation mode, live-tested on Moodle 4.5.12.

### Added
- **In-process mode**: draft and validate STACK questions against this site's own `qtype_stack` +
  Maxima — no external service required. Install the plugin, set an AI provider/model/key, and go.
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
