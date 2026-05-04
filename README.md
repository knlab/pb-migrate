# pb-migrate

[日本語版 / Japanese](README.ja.md)

Command-line tool to sync local AIML projects with [Pandorabots](https://www.pandorabots.com/), built on top of [`spontena/pb-php`](https://github.com/spontena/pb-php).

`pb-migrate` is a modern OSS rewrite of an in-house CLI used at a former employer. It exposes only the publicly documented Pandorabots API surface, ships as a Symfony Console application, and includes an interactive REPL.

## Features

- `init` — scaffold a new AIML project (config + `.env.example` + sample AIML)
- `bot:list` / `bot:create` / `bot:delete` / `bot:files` / `compile` — manage bots on Pandorabots
- `push` — upload local AIML / set / map / substitution / pdefaults / properties to a bot, with content-hash diff detection (add / update / delete) and automatic `compile`
- `pull` — download all bot files into the local project directory
- `cat` — print a single remote file body to stdout (pipe / redirect friendly)
- `file:delete` — surgical deletion of a single remote file
- `diff` — show a unified diff between local and remote
- `status` — show the local sync state of managed bots (no API calls)
- `report` — generate an inspection report of pending changes for handoff documents
- `test` — assert bot replies match expected outputs (CI-friendly exit codes)
- `batch` — run a runbook file of pb-migrate commands
- `alter:list` / `alter:set` / `alter:unset` / `alter:reset` — manage persistent file-body overrides for debug-session probes (auto-applied on every `push`)
- `talk` / `debug` / `atalk` — converse with a bot from the terminal
- `--all` and `--bot 'pattern'` work across `push` / `pull` / `diff` / `compile` / `report` / `status` / `test` for multi-bot operations
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
bot:list                                List bots on Pandorabots (account-wide)
bot:files --bot <botname>               List files stored on a single bot
bot:create <botname>                    Create a bot
bot:delete <botname> [--yes]            Delete a bot (asks for confirmation)
compile [--bot ...|--all]               Compile (verify) one or more bots
cat [<name>] --bot --kind               Print a single remote file body to stdout
file:delete [<name>] --bot --kind       Delete a single remote file
            [--yes]                     (omit name for pdefaults / properties)
push  [--bot ...|--all] [--dry-run]     Push local AIML to bot(s)
                        [--skip-compile]
                        [--prune]
                        [--full-check]
                        [--only=...]
                        [--override n=p]
                        [-i|--interactive]
                        [--properties-upload=additive|full]
pull  [--bot ...|--all] [--only=...]    Pull bot files to the local directory
diff  [--bot ...|--all] [--full-check]  Unified diff between local and remote
                        [--only=...]
status [--bot ...|--all]                Local sync state of managed bots (no API)
report [--bot ...|--all] [--full-check] Inspection report of pending changes
                        [--only=...]    (handoff document format)
test   [--bot ...|--all]                Assert bot replies match expected
       --input X --expect Y             — single inline test, OR
       --file tests.txt                 — load <input>|<expected> per line
batch <runbook.txt>                     Run a list of commands from a file
       [--continue-on-error]            (skip blank lines and `# comments`)
       [--echo]
alter:list  [--bot ...|--all]           List persistent alters configured per bot
alter:set   <name> <path> --bot <bot>   Add/update a persistent alter (saved to config)
alter:unset <name> --bot <bot>          Remove a single persistent alter
alter:reset --bot <bot> [--yes]         Wipe every alter on a bot (debug cleanup)
talk  <input> --bot <botname>           Talk to a bot
debug <input> --bot <botname>           Talk with trace JSON
atalk <input>                           Anonymous talk via PB_BOT_KEY
repl                                    Interactive shell (default)
```

For `--bot`, a glob pattern (`prod.*`) is accepted in addition to an exact bot name. Use `--all` to operate on every bot defined in `pb-migrate.json`.

Inside the REPL, you can issue any of the same subcommands (`bot:list`, `push --bot foo`, etc). Use `exit`, `quit`, or Ctrl-D to leave.

## Push / pull semantics

- **`push`** enumerates local files (extension → `FileKind`), compares them against `getBotFiles()`, and uploads only what differs (SHA-256 content hash). By default `push` is **additive** — files that exist remotely but not locally (including the bot's default files such as `udc`) are reported but not deleted. Pass `--prune` to delete them.
- **`pull`** writes every remote file into the configured directory, restoring the canonical extension (`.aiml`, `.set`, `.map`, `.substitution`, or the bare kind name for `pdefaults` / `properties`).
- **`diff`** runs the same plan and prints a unified diff for each updated file.

Update detection is content-hash based; modification timestamps are not exposed by the Pandorabots API.

### Selective operations

Each of `push`, `pull`, and `diff` accepts `--only` to limit the operation to specific files:

```bash
# only push the greet.aiml — leave everything else alone
pb-migrate push --bot mybot --only greet

# multiple targets, name or kind/name
pb-migrate diff --bot mybot --only greet,fallback,set/colors

# pull only one file from remote
pb-migrate pull --bot mybot --only greet
```

`push` additionally supports two more advanced flags for fine-grained control:

```bash
# temporarily swap the body of greet with a test variant — for THIS push only
pb-migrate push --bot mybot --override greet=variants/greet-test.aiml

# walk through each detected change and confirm individually
pb-migrate push --bot mybot --interactive
```

`--override` accepts multiple times. The substitution lasts only for this command — your project files on disk are not modified.

### Persistent alters (debug-session probes)

`--override` is great for one-shot tweaks but inconvenient when you are running an investigative session: you might keep three or four debug probes active for an hour while talking to the bot, pushing repeatedly between iterations. `pb-migrate.json` can store these as **persistent alters** so they re-apply automatically on every `push`.

Typical use cases:
- inject a category that dumps internal predicates when you talk to the bot
- inject a category that simulates "as if" some state has been written, to exercise a branching path
- once the session is over, strip all alters before the next production push

Manage the set with four commands:

```bash
# add or update an entry
pb-migrate alter:set _dump_predicates variants/dump-predicates.aiml --bot mybot
pb-migrate alter:set greet variants/greet-debug.aiml --bot mybot

# show what's currently configured
pb-migrate alter:list --bot mybot

# remove a single entry
pb-migrate alter:unset greet --bot mybot

# wipe every alter for this bot (debug-session cleanup)
pb-migrate alter:reset --bot mybot
```

This persists the entries under each bot in `pb-migrate.json`:

```json
{
  "bots": {
    "mybot": {
      "directory": "./aiml/mybot",
      "alters": {
        "_dump_predicates": "variants/dump-predicates.aiml",
        "greet": "variants/greet-debug.aiml"
      }
    }
  }
}
```

When `push` runs, the alters are merged into the override set automatically. CLI `--override` still wins on conflict, letting you layer a one-shot test on top of a persistent debug-session alter.

The kind of each alter is inferred from the override file's extension (same rule as `--override`). The alter `name` need not exist as a canonical local file — using a fresh name like `_dump_predicates` adds a brand-new file to the bot's upload set, and `alter:unset` followed by another `push --prune` removes it from the remote.

> ⚠️  Persistent alters live in `pb-migrate.json`, which is typically committed to git. Run `alter:reset` (or remove the entries manually) before merging your config back into a shared branch — the goal of an alter is to leave no trace once the debug session is over.

### Local cache (`.pb-migrate-cache.json`)

To avoid downloading every remote file on every `push` / `diff`, pb-migrate maintains a small JSON cache (`.pb-migrate-cache.json`, gitignored) of the SHA-256 of each file at the time of the last successful push or pull. On the next run:

- if the local file hash matches the cached value, the file is treated as unchanged and **no remote body is fetched**;
- if the local hash differs from the cached value, the file is uploaded as an UPDATE without fetching the remote body first;
- files with no cache entry fall back to the original behavior (fetch remote body, compare hashes).

This brings the API call count for an unchanged project down from O(N) to a single `getBotFiles()`.

If you suspect that someone edited the remote bot directly (e.g. via the Pandorabots dashboard) and you want pb-migrate to reconcile against the *actual* remote state, pass `--full-check`. This bypasses the cache and verifies every conflicting file against a fresh download.

### `propertiesUpload`: additive vs full replace

`.properties` files have two upload strategies, configurable per bot:

```json
{
  "bots": {
    "prod-greeter": {
      "directory": "./aiml/greeter",
      "propertiesUpload": "full"
    }
  }
}
```

| Mode | Behavior | Suited for |
|---|---|---|
| `additive` (default) | Just `PUT` the local body. The Pandorabots API merges with existing remote keys, so any keys not in your local file remain on the server. | Coexisting with dashboard edits made by ops; environments where multiple sources update properties |
| `full` | `DELETE` the remote properties file first, then `PUT` the local body. The local file becomes authoritative — any keys not present locally are removed from the server. | Strict GitOps workflows; eliminating stale keys from previous deployments |

Override per push with `--properties-upload=additive` or `--properties-upload=full`.

### Multi-bot operations

`push` / `pull` / `diff` / `compile` / `status` / `report` / `test` accept either `--all` (every bot in `pb-migrate.json`) or `--bot 'pattern'` (glob, e.g. `prod.*`):

```bash
pb-migrate status                            # all managed bots
pb-migrate push --all --skip-compile         # bulk push, no compile
pb-migrate report --bot 'staging.*'          # handoff report for staging bots
pb-migrate test --all --file regression.txt  # regression suite across bots
```

### Runbooks (`batch`)

A runbook is a plain-text file: one pb-migrate command per line. Comments (`#`) and blank lines are skipped.

```
# weekly-cleanup.txt
# Run every Monday before standup

# 1. Snapshot of state
status --all

# 2. Pull anything that drifted on staging
pull --bot 'staging.*'

# 3. Push pending local changes everywhere
push --all
```

Run with:

```bash
pb-migrate batch weekly-cleanup.txt --echo --continue-on-error
```

`--echo` prints each command for CI logs/audit; `--continue-on-error` keeps going past failures (default is stop-on-first-failure).

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
