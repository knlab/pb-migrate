# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.6.1] — 2026-05-04

Quality / tooling release — "test reinforcement". No user-facing functionality changes; bundles a published JSON Schema for the config plus comprehensive Command-level unit test coverage. **Total unit test count grows from 72 to 127 (+55 tests)**, leaving every CLI command with at least direct unit coverage.

### Added
- **`docs/schema.json`** — published JSON Schema (Draft 7) for `pb-migrate.json`. Served via GitHub Pages at `https://knlab.github.io/pb-migrate/schema.json` (the URL the README example and `init` template have been pointing at since v0.1.0). VS Code, JetBrains IDEs, and most JSON-aware editors pick it up automatically and provide autocomplete, hovers explaining each field, and typo warnings while editing the config.
- **`tests/Unit/SchemaValidationTest`** (9 tests) — guards against drift between the schema and the project. Validates that the example config and the `init` command's generated template both pass the schema, and that representative bad inputs (missing required fields, typoed property names, invalid enum values) are rejected.
- **`tests/Unit/Command/StatusCommandTest`** (6 tests) — local-vs-cache add / update display, clean-state branch, default-to-all-bots when neither `--bot` nor `--all` is given.
- **`tests/Unit/Command/TestCommandTest`** (5 tests) — inline `--input/--expect`, `--file`, CI exit-code contract on mismatch.
- **`tests/Unit/Command/BatchCommandTest`** (6 tests) — missing file, comment-only file, all-success run, stop-on-first-failure default, `--continue-on-error`, `--echo` prefixing.
- **`tests/Unit/Command/PushCommandTest`** (8 tests) — add / no-changes / dry-run / skip-compile / prune (DELETE issued) / no-prune (DELETE skipped) / `--override` uploading under the canonical name (regression guard for the v0.5.0 fix) / `--only`. Uses Guzzle's history middleware to assert the exact URLs and methods called.
- **`tests/Unit/Command/PullCommandTest`** (5 tests) — AIML-extension restoration, properties written as bare `properties`, on-demand local directory creation, `--only` filtering, graceful skipping of files that return 404 (Pandorabots-managed `udc`).
- **`tests/Unit/Command/DiffCommandTest`** (4 tests) — no-differences, local-only marker, remote-only marker, unified diff for an updated file.
- **`tests/Unit/Command/ConversationCommandsTest`** (6 tests) — `talk` (response printing, `client_name`/`session` propagation), `debug` (pretty trace JSON, `--reset`/`--extra` flag propagation), `atalk` (uses /talk?botkey= endpoint, refuses to run when `botKey` is absent).
- **`tests/Unit/Command/BotLifecycleCommandsTest`** (3 tests) — `bot:create` PUTs to /bot/{appId}/{botname}; `bot:delete --yes` issues DELETE; `bot:delete` cancellation on prompt issues no API call.
- **`tests/Unit/Command/CompileCommandTest`** (2 tests) — single-bot `--bot` path hits /verify once; `--all` invokes /verify for every bot in the config.

### Internal
- Added `justinrainbow/json-schema` to `require-dev` for the schema validation tests. No runtime dependency change.

## [0.6.0] — 2026-05-04

### Added
- **`report --since=cache`** — generate the same handoff-friendly inspection report as the default `report`, but with `.pb-migrate-cache.json` as the diff source instead of the live remote bot. No API calls are made. Detects:
  - `(+)` ADD — local file with no cache entry (new since last push/pull)
  - `(*)` UPDATE — local file whose SHA-256 differs from the cache
  - `(-)` DELETE — cache entry with no matching local file (would be removed by `push --prune`)
  - Useful for PR descriptions, handoff notes, and pre-merge sanity checks. Complements the existing `status` command, which shows only the totals.
- New `Sync\CachePlanner` class encapsulates the local-vs-cache diff logic so it can be reused outside the API-bound `BotSync::plan()` path.

### Changed
- `report` default behavior is unchanged. `--since=cache` is opt-in.
- `--since=cache` rejects combination with `--full-check` (the latter requires API access).

### Tests
- New `ReportCommandTest` (6 unit tests) covering the cache-mode add/update/delete detection, empty-cache warning, the `--full-check` conflict, unknown `--since` values, and the clean-state case.

## [0.5.0] — 2026-05-04

### Added
- **Persistent alters**. Four new commands manage per-bot file-body overrides that live in `pb-migrate.json` and re-apply automatically on every `push`:
  - `alter:list [--bot ...|--all]` — show configured alters
  - `alter:set <name> <path> --bot <bot>` — add/update an alter
  - `alter:unset <name> --bot <bot>` — remove a single alter
  - `alter:reset --bot <bot> [--yes]` — remove every alter on a bot (debug-session cleanup)

  Use case: ports the `alter` workflow from the legacy `aimigrate` tool — temporarily inject debug probes (e.g. a category that dumps internal predicates, or one that simulates "as if" some state has been written) and have them auto-apply on every push during an investigative session, then strip them before going to production. The CLI `--override` continues to work and wins on conflict so one-shot tests can layer on top of a persistent alter set.

### Fixed
- **`push --override` was uploading files under the override path's basename, not the canonical name.** For example, `push --bot mybot --override greet=variants/greet-test.aiml` would upload to `/file/greet-test` instead of `/file/greet`, so the canonical `greet` file on the bot was never updated by an override. Existed since v0.2.0; surfaced now while writing the integration test for the new alter feature, which exercised the same code path.

### Changed
- `BotConfig` gains an optional `alters` map field (defaults to empty). Existing `pb-migrate.json` files without the field continue to work unchanged.
- Minimum required `spontena/pb-php` bumped from `^2.1.1` to `^2.1.2`. pb-php v2.1.2 adds an explicit `name` parameter to `PBClient::upload()`; pb-migrate uses it when uploading a file whose local path basename does not match the canonical name on the bot (the `--override` and persistent alter cases).

### Tests
- New `AlterCommandsTest` (8 unit tests) covering set/unset/reset/list, env-var literal preservation on write-back, and missing-path rejection.
- New `AlterPersistenceTest` (2 integration tests) exercising the set → push → unset → push roundtrip against the real Pandorabots API. Also implicitly verifies the `--override` upload-name fix above.

## [0.4.1] — 2026-05-04

### Changed
- Minimum required `spontena/pb-php` bumped from `^2.1` to `^2.1.1`. The pb-migrate v0.4.0 workaround for pb-php v2.1.0's overly strict `fname` assertion in `deleteBotFile()` has been removed; pb-php v2.1.1+ accepts an empty `fname` for kinds whose URL has no filename (`pdefaults`, `properties`).

### Internal
- Cleaned up the three workaround sites (`FileDeleteCommand`, `BotSync` prune path, `BotSync` `propertiesUpload=full` path) so they now pass an empty `fname` directly when the file kind has no name in the URL, instead of a kind-name placeholder.
- No user-facing behavior change.

### Tests
- Added integration tests `BotFilesCommandsTest` covering `bot:files`, `cat`, and `file:delete` end-to-end against the real Pandorabots API (5 scenarios).

## [0.4.0] — 2026-05-03

### Added
- **`bot:files <--bot>` command** — list the files stored on a single bot, grouped by kind, with size and modified timestamps.
- **`cat <name> --bot --kind` command** — print a single remote file's body to stdout. Pdefaults / properties take no name. Output is byte-faithful for safe piping and redirection.
- **`file:delete <name> --bot --kind [--yes]` command** — surgical removal of a single remote file, distinct from `push --prune`. Works for every kind, asks for confirmation by default, keeps the local cache in sync.

### Workarounds
- `deleteBotFile` in spontena/pb-php v2.1.0 asserts a non-empty filename argument even for kinds where the URL ignores it (`pdefaults` / `properties`). pb-migrate passes a harmless placeholder so `file:delete --kind properties` and `push --prune` for properties/pdefaults work today. The proper fix is intended for pb-php v2.1.1.

## [0.3.0] — 2026-05-03

### Added
- **Multi-bot operations**. `push`, `pull`, `diff`, `compile`, `report`, `status`, and `test` accept `--all` (every bot in `pb-migrate.json`) and `--bot 'pattern'` (glob, e.g. `prod.*`). The previous single-`--bot <name>` form continues to work.
- **`status` command** — show the local sync state (file counts, pending add/update vs the local cache) of one or more managed bots without touching the API.
- **`report --next-push` command** — generate an inspection report of pending changes (badges + sizes), suitable for handoff documentation. Distinct from `push --dry-run`, which is action-oriented; `report` is inspection-oriented.
- **`batch <file>` command** — execute a runbook of pb-migrate commands one per line. Supports `# comments`, blank-line skipping, `--continue-on-error`, and `--echo` for CI/audit logs.
- **`test` command** — assert bot replies match expected outputs. Inline form (`--input X --expect Y`) or file form (`--file tests.txt`, one `<input>|<expected>` per line). Returns non-zero exit code on mismatch for CI integration.
- **`propertiesUpload` per-bot setting** in `pb-migrate.json` controls how `.properties` files are uploaded:
  - `additive` (default) — Pandorabots' native upsert behavior; remote keys not in local stay
  - `full` — pb-migrate explicitly deletes the remote properties file before re-uploading, making local authoritative
  - The flag `--properties-upload=...` overrides the per-bot setting for a single push.

### Notes
- Backwards-compatible release. Single-bot `push --bot foo` and `pull --bot foo` still work; the new flags are opt-in.

## [0.2.0] — 2026-05-03

### Added
- `push` / `pull` / `diff` accept `--only=<comma-list>` to restrict the operation to specific names (or `kind/name` pairs).
- `push --override name=path/to/file` (repeatable) — temporarily swap a file body for a single push without renaming or duplicating files. The override does not persist beyond the command.
- `push --interactive` (`-i`) — confirm each detected change individually before applying.
- Japanese README (`README.ja.md`) maintained alongside the English README.

### Notes
- Backwards-compatible release; existing scripts using `push` / `pull` / `diff` without these flags behave exactly as before.

## [0.1.1] — 2026-05-03

### Added
- Local push/pull cache (`.pb-migrate-cache.json`) that records the SHA-256 of each file at the time of the last successful push or pull. This eliminates the per-file `getBotFile()` round-trip on subsequent runs and brings the API call count for an unchanged project from O(N) down to a single `getBotFiles()`.
- `push --full-check` and `diff --full-check` flags to bypass the cache and verify every conflicting file against a fresh remote download.
- `init` now generates a project-local `.gitignore` that excludes `.env`, `.env.local`, and `.pb-migrate-cache.json`.

### Changed
- File hashing switched from SHA-1 to SHA-256.

## [0.1.0] — 2026-05-03

### Added
- Initial release.
