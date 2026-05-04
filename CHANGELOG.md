# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.7.1] — 2026-05-04

Bare-name plumbing was incomplete in v0.7.0 — kinds whose URL has no filename
component (`properties`, `pdefaults`) were stored on disk under the bare kind
name by `pull`, but `push` could not read them back, the diff layer reported
them as ADD even when they were already remote, the `.env` file was loaded
into `$_ENV` but not into `getenv()`, and a few output rough edges hid behind
those bugs. This release closes the loop.

### Fixed
- `FileScanner` now recognises bare-name files (`properties`, `pdefaults`)
  for kinds without a filename in their URL — push picks up the same files
  that pull writes. Files whose name happens to match a kind requiring a
  filename (e.g. a literal `set` with no extension) are still rejected.
- `RemoteIndex` normalises the API listing for those kinds: the server echoes
  `name="pdefaults"` / `name="properties"` as the row label, but local-side
  representation has no name. Mismatch caused diff to report bare-name files
  as ADD on every push after they were already uploaded.
- `EnvLoader` now uses `Symfony\Component\Dotenv\Dotenv::usePutenv()`. Without
  it, the rest of the tool reads credentials via `getenv()` but Symfony Dotenv
  defaults to populating only `$_ENV`, so a fresh install (`config` writes the
  `.env`, a new shell runs `push`) reported `PB_APP_ID is not set` even when
  the file was correct.
- `DiffEngine` filters Pandorabots system-managed files (currently `udc`)
  from the DELETE plan. They returned HTTP 412 on every push; the resulting
  warning had become noise rather than signal.
- `DiffEngine` compares `properties` / `pdefaults` semantically (canonicalised
  JSON) instead of by raw byte hash. Pandorabots reformats those kinds on the
  server, so a single-line upload always disagreed with the pretty-printed
  remote when run with `--verify-remote`.
- `push --dry-run` summary now says "Would push" instead of "Pushed".
- `push --keep-remote-only` no longer prints `[delete]` lines in the plan;
  remote-only files that won't be touched are summarised once instead.
- `push` summary count now reflects what actually hit the wire (excluding
  412-skipped system files and DELETEs suppressed by `--keep-remote-only`).
- `bot:files` listing drops the `size` column (the API consistently reports
  0) and shows `—` for the redundant name in the `Pdefaults` / `Properties`
  sections.
- `bin/pb-migrate --version` now reports the actual release version
  (previously stuck at `0.1.0`).
- pb-php constraint bumped to `^2.1.3`; v2.1.3 fixes the symmetric upload-
  path bug where `PBClient::upload()` rejected bare-name files for kinds
  without a filename component.

### Added
- `add <pattern>` accepts a glob pattern (e.g. `./aiml/dogfood*`) and
  registers every matching directory as a bot. Combining `--bot` with a
  multi-match pattern is rejected; a glob with zero matches is an error.
- REPL: tab completion for command names (when the cursor is on the first
  word) and filesystem paths (everything after).
- REPL: multi-word positional input for `talk` / `debug` / `atalk` is
  auto-quoted, so `talk what is your name --bot foo` works without manual
  quoting. Already-quoted input is left untouched.

## [0.7.0] — 2026-05-04

**Major redesign release. Breaking changes throughout.** A spec review revealed
that pb-migrate had drifted from its actual operational domain (deploying existing
AIML packages to Pandorabots) toward an npm/cargo-style "init a new project" model
that didn't fit. v0.7 realigns: the tool now treats local registration as the
source of truth, separates structure (`pb-migrate.json`) from credentials (`.env`),
and exposes register / unregister / config commands matching the workflow.

Per the project state, no breaking-change migration is provided — the tool was not
yet in real-world use. Existing local `pb-migrate.json` files from v0.6.x will not
be read; recreate them with `pb-migrate add`.

### Removed (breaking)
- `init` — the tool no longer creates new project skeletons or sample AIML. Bots
  are registered from existing directories via `add`.
- `--full-check` flag — renamed to `--verify-remote` everywhere (push / diff / report)
- `--prune` flag — push is now destructive by default; opt out with `--keep-remote-only`
- `report --next-push` — vestigial, replaced by the `--since` mechanism in v0.6.0
- Top-level `appId` / `userKey` / `botKey` / `host` / `defaults` fields in
  `pb-migrate.json` — credentials moved entirely to `.env`
- `files` per-bot field — was reserved for unbuilt glob filtering; removed
- `Sync/DiffEngine::unified()` and `sebastian/diff` dependency — `diff` is
  file-level only now

### Added
- **`add <directory> [--bot <name>] [--force]`** — register an existing AIML
  package directory in `pb-migrate.json`. Bot name defaults to directory basename.
- **`remove <botname>`** — unregister a bot locally; preserves the remote bot
  (use `bot:delete` separately to delete on Pandorabots)
- **`config [--bot <name>] [--show] [--plain] [--app-id X --user-key Y --host Z --bot-key W]`**
  — interactively (or via flags) edit credentials in a project-local `.env`
  managed by the tool with block markers. `--show` displays current values masked,
  `--plain` shows them in plain text.
- **`bot:remote`** — list bots on the Pandorabots account, annotated with which
  ones are locally registered vs. unmanaged, plus a separate "registered but not
  on remote" section
- **`Config/EnvFile`** — block-marker reader/writer for `.env`. Tool-managed
  blocks (`# pb-migrate:begin <id>` … `# pb-migrate:end <id>`) coexist with
  user-managed lines outside the markers.

### Changed (breaking)
- **`pb-migrate.json` schema** — drastically simplified. Now contains only
  `bots` (and optional `$schema`). All credentials live in `.env`. One project
  = one app_id (multi-app_id is out of scope; that pattern came from special
  partnership API tiers, not the public Developer Portal pb-migrate targets).
- **`bot:list` semantics** — now lists LOCAL registered bots (no API call). For
  the previous account-wide listing, use the new `bot:remote`.
- **`bot:create` / `bot:delete`** — refuse to operate on bots that are not
  locally registered first. Run `pb-migrate add` before `bot:create`.
- **`push` is destructive by default** — files on the remote that are missing
  locally are deleted, matching the "local is source of truth" model. Pass
  `--keep-remote-only` to preserve them. Pandorabots-managed files (`udc`)
  are skipped with a warning when their delete returns 412.
- **`diff` output completely rewritten** — file-level only, grouped by action
  (`UPD(N)` yellow / `ADD(N)` green / `DEL(N)` red), no inline content diffs.
  Aimigrate-style.
- **`report` output completely rewritten** — rich handoff format with section
  headings (Updates / Additions / Removals), generation timestamp, `--since`
  mode indicator, total local size summary. ASCII borders by default,
  `--utf8-borders` for box-drawing characters.
- **`debug` default output is now formatted** — type-coloured trace steps
  (begin / match / srai-begin / srai-end / sraix-begin / sraix-end / end) with
  level-based indentation and bold-emphasised values. `--json` for raw JSON
  (jq-friendly).
- **`atalk` requires `--bot <name>`** — bot_key is now per-bot, looked up from
  `PB_BOT_<UPPER_BOTNAME>_KEY`. Project-level `botKey` is gone.
- **`test` is silent on success** — only failures print by default; pass
  `--show-pass` to print PASS as well. Failure colour switched from red to
  yellow (red is reserved for system errors). File format gained backslash
  escapes: `\|` → `|`, `\\` → `\`.
- **`alter:list` defaults to `--all`** — running it without selectors lists
  alters across every registered bot. Output now flags missing override paths
  with `[missing!]` so debug-session-in-flight is visible at a glance.
- **`push` warns when alters are active** — protects against pushing debug
  probes to production by surprise.
- **`cat` output now TTY-aware** — adds a trailing newline only when stdout
  is a terminal, byte-faithful when piped/redirected.

### Removed config / fields
- `pb-migrate.json` no longer accepts `host`, `appId`, `userKey`, `botKey`,
  `defaults`. The schema rejects them.
- Per-bot `files` field is removed.

### Internal
- `BotConfig::$filesPattern` removed
- `ProjectConfig::saveBot` / `removeBot` added (new `add` / `remove` workflow)
- `PBClientFactory::forAtalk(config, botname)` added (per-bot bot_key resolution)
- `Sync/DiffEngine::unified()` removed
- `sebastian/diff` dropped from runtime dependencies

### Tests
- 163 unit tests, 397 assertions, all passing
- New `EnvFileTest`, `AddCommandTest`, `RemoveCommandTest`, `ConfigCommandTest`,
  `BotRemoteCommandTest`
- `InitCommandTest` deleted (init removed)
- All other command tests updated for new schema and behaviour

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
