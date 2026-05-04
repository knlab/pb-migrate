# pb-migrate

[English version](README.md)

既存の AIML パッケージを [Pandorabots](https://www.pandorabots.com/) に展開するためのコマンドラインツール。HTTP クライアントには [`spontena/pb-php`](https://github.com/spontena/pb-php) を使用。

`pb-migrate` は社内で使われていたデプロイ CLI を OSS として書き直したものです。Pandorabots Developer Portal API を対象とし、ローカルの AIML を source of truth とする運用を前提としています。

## 設計の前提

- **ローカル登録が常に正**。リモート操作は登録済 bot に対してのみ可能 (`pb-migrate add` でまず登録する)
- **1 プロジェクト = 1 app_id**。複数 app_id 運用は対象外 (業務提携 API の領域)
- **credentials は `.env` に、構造は `pb-migrate.json` に**。前者は gitignore、後者は commit。両方ともツールが編集する
- **`push` のデフォルトは破壊的**。ローカルに合わせてリモートを書き換える。`--keep-remote-only` で opt-out

## 機能概要

- `add` / `remove` — 既存 AIML パッケージディレクトリの登録・解除
- `config` — `.env` 内の credentials を対話編集 (project / per-bot)
- `bot:list` — 登録済 bot 一覧 (ローカル、API 不要)
- `bot:remote` — Pandorabots アカウント全体の bot 一覧 + 登録/未登録の annotation
- `bot:create` / `bot:delete` / `bot:files` — リモート bot ライフサイクル (登録済必須)
- `compile` — bot を Pandorabots で compile
- `push` — ローカル → リモート (デフォルト破壊的)
- `pull` — リモート → ローカル
- `diff` — ファイル単位差分 (UPD/ADD/DEL グループ + 色)
- `report` — handoff 用リッチレポート
- `status` — ローカル ↔ cache (API 不要)
- `cat` / `file:delete` — リモートの 1 ファイル取得・削除
- `talk` / `debug` / `atalk` — bot との対話
- `test` — bot 応答を期待値と照合
- `batch` — runbook 実行
- `alter:list` / `alter:set` / `alter:unset` / `alter:reset` — debug セッション用の永続 file-body 差し替え
- 引数なしで起動すると対話 REPL に入る

## 動作要件

- PHP 8.1 以上
- ext-json
- Composer

## インストール

グローバル:

```bash
composer global require knlab/pb-migrate
```

`~/.composer/vendor/bin` を `$PATH` に通しておいてください。

プロジェクト固定:

```bash
composer require --dev knlab/pb-migrate
./vendor/bin/pb-migrate --version
```

## クイックスタート

```bash
mkdir -p ~/work/my-bots/aiml/mybot && cd ~/work/my-bots
$EDITOR aiml/mybot/greetings.aiml          # AIML を書く

pb-migrate add ./aiml/mybot                # `mybot` を pb-migrate.json に登録
pb-migrate config                          # PB_APP_ID / PB_USER_KEY を対話で .env に保存

pb-migrate bot:create mybot                # Pandorabots に bot 作成
pb-migrate push --bot mybot                # aiml/mybot/* をアップロード + compile
pb-migrate talk hello --bot mybot
pb-migrate                                 # REPL 起動
```

## 設定ファイル

`pb-migrate.json` はプロジェクト構造のみを保持 (credentials は無し):

```json
{
  "$schema": "https://knlab.github.io/pb-migrate/schema.json",
  "bots": {
    "mybot": { "directory": "./aiml/mybot" },
    "other": { "directory": "./aiml/other", "propertiesUpload": "full" }
  }
}
```

`$schema` URL はリポジトリ内 `docs/schema.json` を GitHub Pages で配信したものを指しています。VS Code・JetBrains 系 IDE などほとんどの JSON 対応エディタが自動的に schema を読み込み、フィールド名の補完、各項目の説明ホバー、`directry` のような typo や `propertiesUpload` の不正値を即座に警告します。

per-bot フィールド:

| Field | 必須 | デフォルト | 備考 |
|---|---|---|---|
| `directory` | yes | — | AIML パッケージのパス。プロジェクトルートからの相対 |
| `propertiesUpload` | no | `additive` | `full` で properties を DELETE → PUT (strict GitOps) |
| `alters` | no | `{}` | canonical 名 → 差し替えファイルパスの map (debug 探針、`alter:*` で管理) |

bot 名は英数字のみ (Pandorabots 制約)。

## Credentials (`.env`、ツール管理)

ツールはプロジェクト直下の `.env` (gitignore) をブロックマーカー付きで書き込みます。利用者が手書きした行は保護されます:

```bash
# pb-migrate:begin app
PB_APP_ID=12345678abcdef
PB_USER_KEY=xxxxxxxxxxxxxxxxxxxx
# pb-migrate:end app

# pb-migrate:begin bot=secretbot
PB_BOT_SECRETBOT_KEY=zzzzzzzzzzzzzzzzz
# pb-migrate:end bot=secretbot

# 利用者の自由領域
MY_OWN_VAR=foo
```

| 環境変数 | 用途 |
|---|---|
| `PB_APP_ID` | Pandorabots application ID (必須) |
| `PB_USER_KEY` | Pandorabots user key (必須) |
| `PB_HOST` | API host。デフォルト `https://api.pandorabots.com` |
| `PB_BOT_<UPPER_BOTNAME>_KEY` | bot 単位の bot_key (atalk 用、必要な bot のみ) |

編集:

```bash
pb-migrate config                                  # 対話で project credentials
pb-migrate config --app-id X --user-key Y          # CI 用フラグ渡し
pb-migrate config --bot mybot                      # 対話で bot_key
pb-migrate config --bot mybot --bot-key VALUE
pb-migrate config --show                           # 全値を mask 表示
pb-migrate config --show --plain                   # 全値を平文表示 (注意)
```

## コマンド一覧

```
add <directory> [--bot <name>] [--force]    パッケージディレクトリを登録
remove <botname> [--yes]                    bot 登録を解除 (リモートは触らない)
config [--bot <name>] [--show] [--plain]    .env の credentials 編集
       [--app-id X --user-key Y]
       [--bot-key Z]
bot:list                                    登録済 bot 一覧 (ローカル、API 不要)
bot:remote                                  アカウント全体の bot 一覧 + 登録 annotation
bot:create <botname>                        登録済 bot を Pandorabots に作成
bot:delete <botname> [--yes]                Pandorabots 上の bot を削除
bot:files --bot <botname>                   1 bot のファイル一覧
compile [--bot ...|--all]                   bot を compile
push  [--bot ...|--all] [--dry-run]         ローカル → リモート (デフォルト破壊的)
                        [--skip-compile]
                        [--keep-remote-only]
                        [--verify-remote]
                        [--only=...]
                        [--override n=p]
                        [-i|--interactive]
                        [--properties-upload=additive|full]
pull  [--bot ...|--all] [--only=...]        bot のファイルをローカルに展開
diff  [--bot ...|--all] [--verify-remote]   ファイル単位 UPD/ADD/DEL グループ
                        [--only=...]
status [--bot ...|--all]                    ローカル ↔ cache (API 不要)
report [--bot ...|--all] [--verify-remote]  リッチ handoff レポート
                        [--only=...]
                        [--since=remote|cache]
                        [--utf8-borders]
test   [--bot ...|--all]                    bot 応答と期待値を照合 (success silent)
       --input X --expect Y                 1 件、または
       --file tests.txt                     <input>|<expected> per line
       [--show-pass]                        PASS も表示
cat [<name>] --bot --kind                   リモート 1 ファイルを stdout
file:delete [<name>] --bot --kind [--yes]   リモート 1 ファイル削除 (pdefaults/properties は name 不要)
batch <runbook.txt> [--continue-on-error]   コマンド runbook 実行
                    [--echo]
talk  <input> --bot <botname>               bot と会話
debug <input> --bot <botname> [--json]      trace 付き会話 (default 整形、--json で raw)
atalk <input> --bot <botname>               bot 単位 bot_key で匿名会話
alter:list [--bot ...|--all]                永続 alter 一覧 (default --all)
alter:set <name> <path> --bot <bot>         alter 追加・更新
alter:unset <name> --bot <bot>              alter 1 件削除
alter:reset --bot <bot> [--yes]             alter 全削除
repl                                        対話シェル (default)
```

`--bot` は完全名のほかに glob (例: `prod.*`) を受け付けます。`--all` で全登録 bot 対象。

## push のセマンティクス

`push` は **デフォルトで破壊的**: ローカルに合わせてリモートを書き換える。リモートにあってローカルに無いファイルは削除されます。これは「ローカル正」モデルの帰結 — ディスク上にあるものが bot にあるべきもの。

リモート側のみのファイルを残したい (例: 他のメンバーがダッシュボードから足したファイル) 場合は `--keep-remote-only` を付けてください。

`udc` のような Pandorabots 管理ファイルは API の制約 (412) で削除不可なので、モードに関わらず警告 skip で続行します。

## diff と report

`diff` はファイル単位の差分のみ (内容 diff は出さない):

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

`report` は handoff 文書 / PR 説明用のリッチフォーマット:

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

両者ともデフォルトはリモートとの比較。`report --since=cache` でローカル cache との比較に切替 (API 不要、前回 push/pull 以降のローカル変更を確認したい時に)。

## 永続 alter (debug セッション用探針)

`--override` は単発差し替え向け、長時間の調査セッション (predicate を dump するカテゴリ、状態を装うカテゴリなどを入れて push を何度も繰り返す) には永続 alter が便利:

```bash
pb-migrate alter:set _dump_predicates variants/dump-predicates.aiml --bot mybot
pb-migrate alter:set greet variants/greet-debug.aiml --bot mybot

pb-migrate push --bot mybot   # 「2 active alter(s) detected」と警告
# … bot に話しかけて確認 …

pb-migrate alter:reset --bot mybot   # 本番 push 前に全クリア
pb-migrate push --bot mybot
```

`alter:list` は path 不在を `[missing!]` で警告マークします。

> ⚠️ alter は `pb-migrate.json` に保存されるため commit に混入しがち。debug セッションが終わったら必ず `alter:reset` してから共有ブランチにマージしてください。

## ローカル cache

毎回リモート全ファイルを取得しないよう、`push` / `diff` は前回成功時の SHA-256 を `.pb-migrate-cache.json` (gitignore) に記録します。ダッシュボード経由での編集が疑われる時は `--verify-remote` でキャッシュをバイパスして実機照合できます。

## テスト

```bash
composer install
composer test                    # PHPUnit unit (HTTP は mock)
composer analyse                 # PHPStan level 6
```

Integration スイートは実 Pandorabots API に対して走り、デフォルトでは実行されません:

```bash
PB_APP_ID=xxx PB_USER_KEY=yyy composer test:integration
```

CI は PHP 8.1 / 8.2 / 8.3 / 8.4 で unit スイートを実行します — 詳細は `.github/workflows/ci.yml`。

## ライセンス

MIT — [`LICENSE`](LICENSE) を参照。
