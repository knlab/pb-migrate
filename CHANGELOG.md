# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
