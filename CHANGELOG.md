# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
