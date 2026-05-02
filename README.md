# pb-migrate

Command-line tool to sync local AIML projects with [Pandorabots](https://www.pandorabots.com/), built on top of [`spontena/pb-php`](https://github.com/spontena/pb-php).

`pb-migrate` is a modern OSS rewrite of an in-house CLI used at a former employer. It exposes only the publicly documented Pandorabots API surface, ships as a Symfony Console application, and includes an interactive REPL.

## Features

- `init` — scaffold a new AIML project (config + `.env.example` + sample AIML)
- `bot:list` / `bot:create` / `bot:delete` / `compile` — manage bots on Pandorabots
- `push` — upload local AIML / set / map / substitution / pdefaults / properties to a bot, with content-hash diff detection (add / update / delete) and automatic `compile`
- `pull` — download all bot files into the local project directory
- `diff` — show a unified diff between local and remote
- `talk` / `debug` / `atalk` — converse with a bot from the terminal
- Run `pb-migrate` with no arguments to drop into an interactive REPL

## Requirements

- PHP 8.1+
- ext-json
- Composer

## Installation

Install globally:

```bash
composer global require knlab/pb-migrate
```

Make sure `~/.composer/vendor/bin` (or the equivalent for your Composer setup) is on your `$PATH`.

Or pin it inside a project:

```bash
composer require --dev knlab/pb-migrate
./vendor/bin/pb-migrate --version
```

## Quickstart

```bash
mkdir my-bot && cd my-bot
pb-migrate init . mybot

cp .env.example .env
$EDITOR .env                    # set PB_APP_ID and PB_USER_KEY

pb-migrate bot:create mybot
pb-migrate push --bot mybot     # uploads aiml/mybot/* and compiles
pb-migrate talk hello mybot
pb-migrate pull --bot mybot     # round-trip the files back
pb-migrate diff --bot mybot     # → no differences
pb-migrate                      # drop into REPL
```

## Configuration

`pb-migrate.json` holds the project metadata (env-substitution syntax `${VAR}` and `${VAR:-default}` is supported):

```json
{
  "$schema": "https://knlab.github.io/pb-migrate/schema.json",
  "host": "${PB_HOST:-https://api.pandorabots.com}",
  "appId": "${PB_APP_ID}",
  "userKey": "${PB_USER_KEY}",
  "botKey": "${PB_BOT_KEY:-}",
  "bots": {
    "mybot": { "directory": "./aiml/mybot", "files": "*" }
  }
}
```

Secrets live in `.env` (which is gitignored by the scaffold) — never commit them. Supported variables:

| Variable | Purpose |
|---|---|
| `PB_APP_ID` | Pandorabots application ID (required) |
| `PB_USER_KEY` | Pandorabots user key (required) |
| `PB_HOST` | API host. Defaults to `https://api.pandorabots.com`. |
| `PB_BOT_KEY` | Bot key for `atalk` (optional) |

## Commands

```
init <directory> [<botname>]            Scaffold a project
bot:list                                List bots
bot:create <botname>                    Create a bot
bot:delete <botname> [--yes]            Delete a bot (asks for confirmation)
compile --bot <botname>                 Compile (verify) a bot
push --bot <botname> [--dry-run]        Push local AIML to the bot
                     [--skip-compile]   (additive by default)
                     [--prune]          Delete remote files missing locally
                     [--full-check]     Bypass the local cache
pull --bot <botname>                    Pull bot files to the local directory
diff --bot <botname> [--full-check]     Unified diff between local and remote
talk  <input> --bot <botname>           Talk to a bot
debug <input> --bot <botname>           Talk with trace JSON
atalk <input>                           Anonymous talk via PB_BOT_KEY
repl                                    Interactive shell (default)
```

Inside the REPL, you can issue any of the same subcommands (`bot:list`, `push --bot foo`, etc). Use `exit`, `quit`, or Ctrl-D to leave.

## Push / pull semantics

- `push` enumerates local files (extension → `FileKind`), compares them against `getBotFiles()`, and uploads only what differs (SHA-256 content hash). By default `push` is **additive** — files that exist remotely but not locally (including the bot's default files such as `udc`) are reported but not deleted. Pass `--prune` to delete them.

### Local cache (`.pb-migrate-cache.json`)

To avoid downloading every remote file on every `push` / `diff`, pb-migrate maintains a small JSON cache (`.pb-migrate-cache.json`, gitignored) of the SHA-256 of each file at the time of the last successful push or pull. On the next run:

- if the local file hash matches the cached value, the file is treated as unchanged and **no remote body is fetched**;
- if the local hash differs from the cached value, the file is uploaded as an UPDATE without fetching the remote body first;
- files with no cache entry fall back to the original behavior (fetch remote body, compare hashes).

This brings the API call count for an unchanged project down from O(N) to a single `getBotFiles()`, matching the experience of the legacy `aimigrate` workflow.

If you suspect that someone edited the remote bot directly (e.g. via the Pandorabots dashboard) and you want pb-migrate to reconcile against the *actual* remote state, pass `--full-check`. This bypasses the cache and verifies every conflicting file against a fresh download.
- `pull` writes every remote file into the configured directory, restoring the canonical extension (`.aiml`, `.set`, `.map`, `.substitution`, or the bare kind name for `pdefaults` / `properties`).
- `diff` runs the same plan and prints a unified diff for each updated file.

Update detection is content-hash based; modification timestamps are not exposed by the Pandorabots API.

## Testing

```bash
composer install
composer test                    # PHPUnit unit suite (mocked HTTP)
composer analyse                 # PHPStan level 6
```

The integration suite hits the real Pandorabots API and is **not** run by default. Provide credentials and select the `integration` suite explicitly:

```bash
PB_APP_ID=xxx PB_USER_KEY=yyy composer test:integration
```

To exercise `atalk`, additionally set `PB_BOT_KEY` for an existing, compiled bot whose bot key has been issued in the Pandorabots dashboard.

CI runs the unit suite on PHP 8.1 / 8.2 / 8.3 / 8.4 — see `.github/workflows/ci.yml`.

## License

MIT — see [`LICENSE`](LICENSE).
