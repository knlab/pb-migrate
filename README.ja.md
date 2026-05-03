# pb-migrate

[English version](README.md)

ローカルの AIML プロジェクトを [Pandorabots](https://www.pandorabots.com/) と同期させるためのコマンドラインツール。HTTP クライアントには [`spontena/pb-php`](https://github.com/spontena/pb-php) を使用。

`pb-migrate` は、以前の勤務先で社内利用されていた CLI を OSS として書き直したものです。Pandorabots の公開 API のみを使い、Symfony Console アプリケーションとして動作し、対話 REPL も搭載しています。

## 機能概要

- `init` — 新しい AIML プロジェクトを生成 (設定ファイル + `.env.example` + サンプル AIML)
- `bot:list` / `bot:create` / `bot:delete` / `bot:files` / `compile` — Pandorabots 上の bot 管理
- `push` — ローカルの AIML / set / map / substitution / pdefaults / properties を bot にアップロード。SHA-256 ハッシュベースの差分判定 (追加 / 更新 / 削除) と自動 `compile` 付き
- `pull` — bot 上のファイルをローカルに一括ダウンロード
- `cat` — リモートの 1 ファイルを stdout に出力 (パイプ・リダイレクト連携)
- `file:delete` — リモートの 1 ファイルだけを外科的に削除
- `diff` — ローカルとリモートの unified diff を表示
- `status` — pb-migrate.json で管理する bot の同期状態を表示 (API 呼び出し無し)
- `report` — 引き継ぎ文書向けの保留中変更レポートを生成
- `test` — bot の応答を期待値と照合 (CI 連携用 exit code)
- `batch` — pb-migrate コマンドの runbook を実行
- `talk` / `debug` / `atalk` — ターミナルから bot と会話
- `--all` および `--bot 'pattern'` で `push` / `pull` / `diff` / `compile` / `report` / `status` / `test` をマルチ bot 一括実行
- 引数なしで `pb-migrate` を起動すると対話 REPL に入る

## 動作要件

- PHP 8.1 以上
- ext-json
- Composer

## インストール

グローバルに入れる場合:

```bash
composer global require knlab/pb-migrate
```

`~/.composer/vendor/bin` (環境によっては別パス) を `$PATH` に通しておいてください。

プロジェクトに固定する場合:

```bash
composer require --dev knlab/pb-migrate
./vendor/bin/pb-migrate --version
```

## クイックスタート

```bash
mkdir my-bot && cd my-bot
pb-migrate init . mybot

cp .env.example .env
$EDITOR .env                    # PB_APP_ID と PB_USER_KEY を設定

pb-migrate bot:create mybot
pb-migrate push --bot mybot     # aiml/mybot/* をアップロードして compile
pb-migrate talk hello mybot
pb-migrate pull --bot mybot     # ファイルをラウンドトリップで取り直し
pb-migrate diff --bot mybot     # → 差分なし
pb-migrate                      # REPL に入る
```

## 設定ファイル

`pb-migrate.json` がプロジェクト設定です (環境変数展開 `${VAR}` および `${VAR:-default}` をサポート):

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

シークレットは `.env` (init で生成される `.gitignore` に追加済) に置き、絶対にコミットしないでください。利用できる環境変数:

| 環境変数 | 用途 |
|---|---|
| `PB_APP_ID` | Pandorabots application ID (必須) |
| `PB_USER_KEY` | Pandorabots user key (必須) |
| `PB_HOST` | API ホスト。デフォルトは `https://api.pandorabots.com` |
| `PB_BOT_KEY` | `atalk` 用の bot key (任意) |

## コマンド一覧

```
init <directory> [<botname>]            プロジェクト雛型を生成
bot:list                                Pandorabots アカウント全体の bot 一覧
bot:files --bot <botname>               1 bot のファイル一覧
bot:create <botname>                    bot を作成
bot:delete <botname> [--yes]            bot を削除 (確認プロンプトあり)
compile [--bot ...|--all]               1 つ以上の bot を compile (verify)
cat [<name>] --bot --kind               リモートの 1 ファイルを stdout に出力
file:delete [<name>] --bot --kind       リモートの 1 ファイルを削除
            [--yes]                     (pdefaults / properties は name 不要)
push  [--bot ...|--all] [--dry-run]     ローカル AIML を bot に push
                        [--skip-compile]
                        [--prune]
                        [--full-check]
                        [--only=...]
                        [--override n=p]
                        [-i|--interactive]
                        [--properties-upload=additive|full]
pull  [--bot ...|--all] [--only=...]    bot のファイルをローカルにダウンロード
diff  [--bot ...|--all] [--full-check]  ローカルとリモートの unified diff
                        [--only=...]
status [--bot ...|--all]                管理 bot のローカル同期状態 (API 呼び出し無し)
report [--bot ...|--all] [--full-check] 引き継ぎ文書向けの保留中変更レポート
                        [--only=...]
test   [--bot ...|--all]                bot の応答を期待値と照合
       --input X --expect Y             — 1 件をインライン記述、または
       --file tests.txt                 — <input>|<expected> を 1 行ずつ読む
batch <runbook.txt>                     runbook ファイルからコマンドを連続実行
       [--continue-on-error]            (空行と `# コメント` はスキップ)
       [--echo]
talk  <input> --bot <botname>           bot と会話
debug <input> --bot <botname>           trace JSON 付きで会話
atalk <input>                           PB_BOT_KEY を使った匿名会話
repl                                    対話シェル (デフォルト)
```

`--bot` は完全名のほかに glob (例: `prod.*`) を受け付けます。`--all` で `pb-migrate.json` の全 bot を対象にできます。

REPL 内では同じサブコマンドを (`bot:list`, `push --bot foo` のように) 入力できます。`exit` / `quit` / Ctrl-D で抜けます。

## push / pull の挙動

- **`push`**: ローカルファイルを拡張子 (→ `FileKind`) で列挙し、`getBotFiles()` の結果と SHA-256 ハッシュで差分判定し、変更分のみアップロードします。デフォルトは **追加のみ** — リモートにあってローカルに無いファイル (新規 bot に付随する `udc` などのデフォルトファイルを含む) は報告するだけで削除しません。`--prune` を付けると削除します。
- **`pull`**: リモートのファイルを設定された directory に書き出します。拡張子は復元します (`.aiml` / `.set` / `.map` / `.substitution`、`pdefaults` / `properties` は kind 名そのまま)。
- **`diff`**: 同じ計画を実行し、更新ファイルごとに unified diff を出力します。

差分判定は内容ハッシュベースです。Pandorabots API は更新タイムスタンプを返さないので mtime は使いません。

### 対象を絞った操作

`push` / `pull` / `diff` のいずれも `--only` で対象ファイルを限定できます:

```bash
# greet.aiml だけ push、他は触らない
pb-migrate push --bot mybot --only greet

# 複数指定 (name 単独でも kind/name でも)
pb-migrate diff --bot mybot --only greet,fallback,set/colors

# リモートから 1 ファイルだけ取得
pb-migrate pull --bot mybot --only greet
```

`push` にはさらに細粒度の制御フラグが 2 つあります:

```bash
# greet の本文をテスト用に一時差し替え (この push 限定)
pb-migrate push --bot mybot --override greet=variants/greet-test.aiml

# 各検出変更について個別に確認しながら適用
pb-migrate push --bot mybot --interactive
```

`--override` は複数指定可能。差し替えはこのコマンド実行中だけで、ディスク上のファイルは変更されません。

### ローカルキャッシュ (`.pb-migrate-cache.json`)

`push` / `diff` の毎回でリモート全ファイルをダウンロードすることを避けるため、pb-migrate は小さな JSON キャッシュ (`.pb-migrate-cache.json`、自動 gitignore) を保持します。最後に push または pull が成功した時点での各ファイルの SHA-256 を覚えており、次回実行時は:

- ローカルのハッシュがキャッシュ値と一致 → **リモート本文を取得せず**変更なしと判定
- ローカルのハッシュがキャッシュ値と異なる → リモート取得をスキップしてローカルを UPDATE としてアップロード
- キャッシュにエントリが無い → 従来動作 (リモート本文を取得して比較)

これで変更の無いプロジェクトの API 呼び出し回数が O(N) から `getBotFiles()` 1 回だけに減ります。

ダッシュボード等から誰かがリモートを直接編集した可能性があり、**実際のリモート状態と突き合わせ直したい**場合は `--full-check` を付けてください。キャッシュをバイパスし、衝突する全ファイルについて改めてダウンロードして比較します。

### `propertiesUpload`: 追記 (additive) と全置換 (full)

`.properties` ファイルのアップロード戦略を bot 単位で選べます:

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

| モード | 挙動 | 想定用途 |
|---|---|---|
| `additive` (デフォルト) | ローカルの内容を `PUT` するだけ。Pandorabots API はリクエストに無いキーをサーバ上に残す (= upsert)。ダッシュボードや他経路で追加されたキーは保たれる | ops チームのダッシュボード編集と pb-migrate を共存させる運用 |
| `full` | `DELETE` でリモート properties を一掃してから `PUT`。ローカルが authoritative になり、ローカルに無いキーはサーバから消える | ローカルファイルを真とする厳格な GitOps、過去の deploy で残った古いキーの掃除 |

push 時に `--properties-upload=additive` または `--properties-upload=full` で bot 設定を上書き可能。

### マルチ bot 操作

`push` / `pull` / `diff` / `compile` / `status` / `report` / `test` は `--all` (`pb-migrate.json` 全 bot) または `--bot 'pattern'` (glob、例: `prod.*`) を受け付けます:

```bash
pb-migrate status                            # 管理対象の全 bot
pb-migrate push --all --skip-compile         # 一括 push、compile はスキップ
pb-migrate report --bot 'staging.*'          # staging 系 bot の引き継ぎレポート
pb-migrate test --all --file regression.txt  # 回帰テストを全 bot で実行
```

### Runbook (`batch`)

runbook はプレーンテキストファイルで、1 行 1 コマンド。`#` コメントと空行はスキップされます。

```
# weekly-cleanup.txt
# 毎週月曜の standup 前に実行

# 1. 状態スナップショット
status --all

# 2. staging で drift したものを取り戻す
pull --bot 'staging.*'

# 3. 未送信のローカル変更を全 bot に push
push --all
```

実行:

```bash
pb-migrate batch weekly-cleanup.txt --echo --continue-on-error
```

`--echo` は各コマンドを実行前に表示 (CI ログ・監査向け)、`--continue-on-error` は失敗しても続行 (デフォルトは最初の失敗で停止)。

## テスト

```bash
composer install
composer test                    # PHPUnit unit (HTTP は mock)
composer analyse                 # PHPStan level 6
```

Integration スイートは実 Pandorabots API に対して走り、デフォルトでは実行されません。クレデンシャルを与えて `integration` スイートを明示的に指定してください:

```bash
PB_APP_ID=xxx PB_USER_KEY=yyy composer test:integration
```

`atalk` まで検証するには、追加で `PB_BOT_KEY` (Pandorabots ダッシュボードで bot key を発行済の compile 済 bot 用) を設定してください。

CI は PHP 8.1 / 8.2 / 8.3 / 8.4 で unit スイートを実行します — 詳細は `.github/workflows/ci.yml` を参照。

## ライセンス

MIT — [`LICENSE`](LICENSE) を参照。
