# Changelog

All notable changes to **local_stackforge** are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and this project adheres to
[Semantic Versioning](https://semver.org/).

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
