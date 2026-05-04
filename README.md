# pb-migrate

[日本語版 / Japanese](README.ja.md)

Command-line tool to manage existing AIML packages on [Pandorabots](https://www.pandorabots.com/), built on top of [`spontena/pb-php`](https://github.com/spontena/pb-php).

`pb-migrate` is the OSS rewrite of an in-house deployment CLI. The tool treats your local AIML files as the source of truth, registers existing package directories, and pushes them to Pandorabots' Developer Portal API.

## Concepts

- **Local registration is the source of truth.** A bot must be registered locally (`pb-migrate add`) before it can be operated on remotely. Pandorabots-side state never authoritatively drives the local config.
- **One project = one app_id.** A `pb-migrate.json` file describes a project; all bots under it share one Pandorabots application ID. Multi-app_id setups are out of scope.
- **Credentials live in `.env`, structure in `pb-migrate.json`.** The JSON file is committable; the `.env` is gitignored. Both can be edited by the tool.
- **Default `push` is destructive.** It rewrites the remote bot to match local. Pass `--keep-remote-only` to opt out.

## Features

- `add` / `remove` — register or unregister an existing AIML package directory
- `config` — interactively edit credentials (`.env`) for project or per-bot bot_keys
- `bot:list` — show registered bots (local, no API call)
- `bot:remote` — show bots on the Pandorabots account, annotated with registration state
- `bot:create` / `bot:delete` / `bot:files` — remote bot lifecycle (require local registration)
- `compile` — verify bots on Pandorabots
- `push` — upload local files (destructive: rewrites remote to match local)
- `pull` — download remote files into the local directory
- `diff` — show file-level changes (UPD/ADD/DEL grouped, color-coded)
- `report` — rich handoff report of pending changes
- `status` — local sync state vs. cache (no API)
- `cat` / `file:delete` — inspect or delete a single remote file
- `talk` / `debug` / `atalk` — converse with a bot from the terminal
- `test` — assert bot replies match expected outputs
- `batch` — run a runbook of pb-migrate commands
- `alter:list` / `alter:set` / `alter:unset` / `alter:reset` — persistent file-body overrides for debug-session probes
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
mkdir -p ~/work/my-bots/aiml/mybot && cd ~/work/my-bots
$EDITOR aiml/mybot/greetings.aiml          # write your AIML

pb-migrate add ./aiml/mybot                # registers `mybot` in pb-migrate.json
pb-migrate config                          # prompts for PB_APP_ID / PB_USER_KEY → writes .env

pb-migrate bot:create mybot                # creates the bot on Pandorabots
pb-migrate push --bot mybot                # uploads aiml/mybot/* and compiles
pb-migrate talk hello --bot mybot
pb-migrate                                 # drop into REPL
```

## Configuration

`pb-migrate.json` holds the project structure (no credentials):

```json
{
  "$schema": "https://knlab.github.io/pb-migrate/schema.json",
  "bots": {
    "mybot": { "directory": "./aiml/mybot" },
    "other": { "directory": "./aiml/other", "propertiesUpload": "full" }
  }
}
```

The `$schema` URL points at the published JSON Schema. VS Code, JetBrains IDEs, and most JSON-aware editors pick it up automatically — autocomplete on field names, hovers explaining what each field does, and instant warnings on typos like `directry` or invalid `propertiesUpload` values.

Per-bot fields:

| Field | Required | Default | Notes |
|---|---|---|---|
| `directory` | yes | — | Path to the AIML package directory; relative to project root |
| `propertiesUpload` | no | `additive` | `full` to delete remote properties before re-uploading (strict GitOps) |
| `alters` | no | `{}` | Map of canonical name → override file path (debug-session probes; managed by `alter:*` commands) |

Bot names must be alphanumeric (Pandorabots constraint).

## Credentials (`.env`, tool-managed)

The tool writes a project-local `.env` (gitignored) using block markers so user-managed lines are preserved:

```bash
# pb-migrate:begin app
PB_APP_ID=12345678abcdef
PB_USER_KEY=xxxxxxxxxxxxxxxxxxxx
# pb-migrate:end app

# pb-migrate:begin bot=secretbot
PB_BOT_SECRETBOT_KEY=zzzzzzzzzzzzzzzzz
# pb-migrate:end bot=secretbot

# user-managed lines below this line are preserved on every tool write
MY_OWN_VAR=foo
```

| Variable | Purpose |
|---|---|
| `PB_APP_ID` | Pandorabots application ID (required) |
| `PB_USER_KEY` | Pandorabots user key (required) |
| `PB_HOST` | API host. Defaults to `https://api.pandorabots.com` |
| `PB_BOT_<UPPER_BOTNAME>_KEY` | Per-bot bot_key for `atalk`. Only the bots that need anonymous talk have one. |

Edit via:

```bash
pb-migrate config                                  # prompts for project credentials
pb-migrate config --app-id X --user-key Y          # CI / scripted form
pb-migrate config --bot mybot                      # prompts for that bot's bot_key
pb-migrate config --bot mybot --bot-key VALUE
pb-migrate config --show                           # display all values
```

The interactive prompts show the current value; press Enter to keep it,
type a new value to update, or type `-` to clear an optional field.

## A note on `atalk` and bot_keys

`atalk` (anonymous talk) requires a per-bot **bot_key**. Pandorabots issues
bot_keys through their dashboard UI (developer.pandorabots.com), not through
the Developer Portal API.

Heads-up: bots created via the API (`pb-migrate bot:create`, or anything
else hitting `PUT /bot/...`) **do not appear in the dashboard's bot list**
during the API trial / Developer Portal tier we tested against. There seems
to be no API endpoint that returns the bot_key either. The practical
consequence: if you want `atalk` to work, create the bot on the dashboard
first and `pb-migrate add` it locally, rather than using `bot:create` to
spin it up via the API.

`talk` and `debug` use the regular user_key authentication and work fine
for either path.

## Commands

```
add <directory> [--bot <name>] [--force]    Register a package directory
remove <botname> [--yes]                    Unregister a bot (does not touch remote)
config [--bot <name>] [--show]              Edit credentials in .env
       [--app-id X --user-key Y]
       [--bot-key Z]
bot:list                                    List registered bots (local, no API)
bot:remote                                  List bots on the Pandorabots account, annotated
bot:create <botname>                        Create a registered bot on Pandorabots
bot:delete <botname> [--yes]                Delete a bot on Pandorabots
bot:files --bot <botname>                   List files stored on a single bot
compile [--bot ...|--all]                   Compile (verify) one or more bots
push  [--bot ...|--all] [--dry-run]         Push local files to bot(s); destructive by default
                        [--skip-compile]    (rewrites remote to match local; --keep-remote-only to opt out)
                        [--keep-remote-only]
                        [--verify-remote]
                        [--only=...]
                        [--override n=p]
                        [-i|--interactive]
                        [--properties-upload=additive|full]
pull  [--bot ...|--all] [--only=...]        Pull bot files to the local directory
diff  [--bot ...|--all] [--verify-remote]   File-level UPD/ADD/DEL grouped diff
                        [--only=...]
status [--bot ...|--all]                    Local sync state of registered bots (no API)
report [--bot ...|--all] [--verify-remote]  Rich handoff report of pending changes
                        [--only=...]
                        [--since=remote|cache]
                        [--utf8-borders]
test   [--bot ...|--all]                    Assert bot replies match expected; silent on success
       --input X --expect Y                 inline test, OR
       --file tests.txt                     <input>|<expected> per line
       [--show-pass]                        also print PASS lines
cat [<name>] --bot --kind                   Print a single remote file body to stdout
file:delete [<name>] --bot --kind [--yes]   Delete a single remote file (omit name for pdefaults / properties)
batch <runbook.txt> [--continue-on-error]   Execute a list of pb-migrate commands from a file
                    [--echo]
talk  <input> --bot <botname>               Talk to a bot
debug <input> --bot <botname> [--json]      Talk with trace; default formatted, --json for raw
atalk <input> --bot <botname>               Anonymous talk via per-bot bot_key
alter:list [--bot ...|--all]                List persistent alters (defaults to --all)
alter:set <name> <path> --bot <bot>         Add or update a persistent alter
alter:unset <name> --bot <bot>              Remove a single persistent alter
alter:reset --bot <bot> [--yes]             Wipe every alter on a bot
repl                                        Interactive shell (default)
```

For `--bot`, a glob pattern (`prod.*`) is accepted in addition to an exact bot name. `--all` operates on every registered bot.

## Push semantics

`push` is **destructive by default**: it rewrites the remote bot to match local. Files that exist on the remote but not locally are deleted. This matches the "local is source of truth" model — what's on disk is what should be on the bot.

To preserve remote-only files (e.g. files added via the Pandorabots dashboard by other team members), pass `--keep-remote-only`.

Pandorabots-managed files like `udc` are never deletable (412 from the API); pb-migrate skips them with a warning regardless of mode.

## Diff and report

`diff` shows file-level changes only — which files differ, in what category — without inline content diffs:

```
mybot:
URL: https://api.pandorabots.com
BOT: app-x/mybot

UPD(1):
    file/greet
ADD(1):
    file/farewell
DEL(1):
    file/oldfile
```

`report` produces a richer document suitable for handoff notes / PR descriptions:

```
============================================================
Pending changes for bot mybot
============================================================
Generated: 2026-05-04 18:30 (--since=remote)

--- Updates (1) ----------------------------------------
  file/greet  (3.1 KB)

--- Additions (1) --------------------------------------
  file/farewell  (0.8 KB)

--- Removals (1) ---------------------------------------
  file/oldfile  (remote-only)

--- Summary ----------------------------------------
  1 added, 1 updated, 1 remote-only
  Total local size: 3.9 KB
```

Both default to comparing against live remote. Pass `--since=cache` on `report` to compare against the local cache instead (no API call) — useful for seeing what changed locally since the last push/pull.

## Persistent alters (debug-session probes)

`--override` is great for one-shot tweaks. For longer investigative sessions where you want a few debug probes (a category that dumps internal predicates, one that simulates state, etc.) to re-apply on every push, register them as persistent alters:

```bash
pb-migrate alter:set _dump_predicates variants/dump-predicates.aiml --bot mybot
pb-migrate alter:set greet variants/greet-debug.aiml --bot mybot

pb-migrate push --bot mybot   # warns "2 active alter(s) detected"
# … iterate, talk to bot, observe …

pb-migrate alter:reset --bot mybot   # clear all probes before production push
pb-migrate push --bot mybot
```

`alter:list` flags missing override paths with `[missing!]` so a session in flight is visible at a glance.

> ⚠️ Persistent alters live in `pb-migrate.json`, which is typically committed. Run `alter:reset` before merging your config back into a shared branch — alters are meant to leave no trace once the debug session is over.

## Local cache

To avoid re-fetching every remote file on every `push` / `diff`, pb-migrate maintains a JSON cache (`.pb-migrate-cache.json`, gitignored) of the SHA-256 of each file at the time of the last successful push or pull. Pass `--verify-remote` to bypass the cache when you suspect dashboard edits or cache corruption.

## Testing the code

```bash
composer install
composer test                    # PHPUnit unit suite (mocked HTTP)
composer analyse                 # PHPStan level 6
```

The integration suite hits the real Pandorabots API and is **not** run by default. Provide credentials and select the integration suite explicitly:

```bash
PB_APP_ID=xxx PB_USER_KEY=yyy composer test:integration
```

CI runs the unit suite on PHP 8.1 / 8.2 / 8.3 / 8.4 — see `.github/workflows/ci.yml`.

## License

MIT — see [`LICENSE`](LICENSE).
