# Day 14 — デプロイ＆仕上げ（最終日）

2週間の集大成として、本番デプロイの準備を整え、ポートフォリオとして公開できる状態に仕上げます。

---

## 今日のゴール

```
【デプロイ準備】
  - 本番環境チェックリストを通過する
  - .env.example を整備する
  - Railway でアプリを公開する

【ポートフォリオ仕上げ】
  - README.md をポートフォリオ向けに書き直す
  - GitHub リポジトリを整備する
```

---

## PART 1 — 本番デプロイ前チェックリスト

### アプリケーション設定の確認

```bash
# キャッシュをクリアしてから確認
php artisan optimize:clear

# マイグレーション状態（全て Ran になっているか）
php artisan migrate:status

# ルート一覧（auth ミドルウェアの漏れがないか）
php artisan route:list --columns=method,uri,middleware
```

### 本番環境と開発環境の `.env` の違い

```dotenv
# ❌ 開発時
APP_ENV=local
APP_DEBUG=true

# ✅ 本番時（エラー内容を画面に表示しない）
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-app.up.railway.app

# ❌ 開発時
CACHE_STORE=file
QUEUE_CONNECTION=database
SESSION_DRIVER=file

# ✅ 本番時（Redis で高速化）
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

### フロントエンドの本番ビルド

```bash
# 圧縮・最適化されたアセットを生成
npm run build

# ビルド結果を確認
ls public/build/
```

---

## PART 2 — `.env.example` を整備する

`.env.example` は「どんな環境変数が必要か」を示す設計書です。  
**機密値（実際のキー・パスワード）は含めず、変数名とコメントだけを記述します。**

```dotenv
APP_NAME=TaskFlow
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_TIMEZONE=Asia/Tokyo
APP_LOCALE=ja

LOG_CHANNEL=stack
LOG_LEVEL=debug

# ---- データベース ----
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=taskflow
DB_USERNAME=
DB_PASSWORD=

# ---- キャッシュ・セッション・キュー ----
CACHE_STORE=file           # 本番では redis を推奨
SESSION_DRIVER=file        # 本番では redis を推奨
QUEUE_CONNECTION=database  # 本番では redis を推奨

# ---- メール ----
MAIL_MAILER=log            # 本番では smtp / ses などに変更
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

# ---- GitHub OAuth（Socialite） ----
GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=
GITHUB_REDIRECT_URI="${APP_URL}/auth/github/callback"

# ---- Sanctum（API 認証） ----
SANCTUM_STATEFUL_DOMAINS=localhost:8000

# ---- Laravel Reverb（WebSocket） ----
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

# ---- Redis（本番環境） ----
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# ---- デバッグ（開発時のみ true） ----
DEBUGBAR_ENABLED=false
```

**`.env.example` の目的：**

| 対象 | 使い方 |
|------|--------|
| 新しいチームメンバー | `cp .env.example .env` して必要な値を埋める |
| 採用担当者 | どんな外部サービスと連携しているか一目でわかる |
| 本番サーバー | どの変数を設定すればいいか把握できる |

---

## PART 3 — Railway でデプロイする

### Railway を選ぶ理由

| 比較項目 | Railway | Heroku | Fly.io |
|---------|---------|--------|--------|
| 無料枠 | あり（月 $5 クレジット） | なし | あり（制限あり） |
| MySQL | ✅ | PostgreSQL のみ | ✅ |
| Redis | ✅ | 有料 | ✅ |
| GitHub 連携 | ✅ 簡単 | ✅ | Docker が必要 |
| 難易度 | ⭐ 簡単 | ⭐⭐ | ⭐⭐⭐ |

### `nixpacks.toml` — ビルド設定

Railway は Nixpacks でビルドを自動検出します。Laravel 専用の設定をプロジェクトルートに置きます：

```toml
[phases.setup]
nixPkgs = [
    'php83',
    'php83Extensions.pdo_mysql',
    'php83Extensions.mbstring',
    'php83Extensions.xml',
    'php83Extensions.curl',
    'php83Extensions.zip',
    'php83Extensions.gd',
    'nodejs_20',
    'npm'
]

[phases.install]
cmds = [
    'composer install --no-dev --optimize-autoloader',
    'npm ci',
    'npm run build',
]

[phases.build]
cmds = [
    'php artisan config:cache',
    'php artisan route:cache',
    'php artisan view:cache',
    'php artisan migrate --force',  # --force で本番でも確認なしで実行
    'php artisan storage:link',
]

[start]
cmd = 'php artisan serve --host=0.0.0.0 --port=$PORT'
```

**各フェーズの役割：**

| フェーズ | 実行タイミング | 内容 |
|---------|--------------|------|
| `setup` | ビルド前 | PHP・Node.js などのシステムパッケージを用意 |
| `install` | ビルド中 | Composer・npm の依存パッケージをインストール |
| `build` | デプロイ前 | Laravel のキャッシュ生成・マイグレーション |
| `start` | 起動時 | アプリケーションサーバーを起動 |

### Railway 設定手順

1. [railway.app](https://railway.app) でアカウント作成
2. **New Project** → **Deploy from GitHub repo** → リポジトリを選択
3. **Add Service** → **Database** → **MySQL** を追加
4. **Add Service** → **Redis** を追加
5. **Variables** タブで本番環境変数を設定

### 本番環境変数の設定（Railway）

```
APP_ENV=production
APP_DEBUG=false
APP_KEY=（php artisan key:generate --show の値）
APP_URL=https://your-app.up.railway.app

DB_CONNECTION=mysql
DB_HOST=（Railway MySQL の HOST 変数を参照）
DB_PORT=3306
DB_DATABASE=railway
DB_USERNAME=root
DB_PASSWORD=（Railway MySQL の PASSWORD 変数を参照）

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_URL=（Railway Redis の URL 変数を参照）

MAIL_MAILER=log
GITHUB_CLIENT_ID=（GitHub OAuth App の値）
GITHUB_CLIENT_SECRET=（GitHub OAuth App の値）
GITHUB_REDIRECT_URI=https://your-app.up.railway.app/auth/github/callback
```

### GitHub OAuth App の redirect URI を更新

[github.com/settings/developers](https://github.com/settings/developers) から  
**Authorization callback URL** を本番 URL に変更：

```
https://your-app.up.railway.app/auth/github/callback
```

---

## PART 4 — ポートフォリオ用 README

`README.md` にポートフォリオとして必要な情報を盛り込みます。

**構成：**

```
# タイトル + バッジ（Laravel・Vue・TypeScript のバージョン）
## 概要（アプリの説明 + デモ URL）
## 主な機能（テーブル形式で一覧）
## 技術スタック（バックエンド・フロントエンド・インフラ）
## アーキテクチャのポイント（フロー図）
## ローカル環境構築（コマンド付き手順）
## テスト（実行コマンド）
## API ドキュメント（Scramble URL）
## 開発ログ（14日分のリンク）
```

**採用担当者に伝わるアピールポイントを README に含める：**

- 設計の意図（なぜこの構成にしたか）
- 技術的な挑戦（WebSocket・Queue・キャッシュ戦略）
- コード品質への取り組み（Larastan Level 5・Pint・テスト）

---

## PART 5 — GitHub リポジトリの整備

### `.gitignore` の最終確認

```bash
git ls-files | grep "\.env"   # .env が追跡されていないか確認（何も出ないこと）
```

### 最終コミット

```bash
git add .
git status   # .env が含まれていないことを必ず確認！
git commit -m "feat: complete TaskFlow portfolio app"
git push origin main
```

### GitHub の About セクション設定

| 項目 | 内容 |
|------|------|
| Description | 🚀 チーム向けタスク管理アプリ。Laravel 12 + Vue 3 + WebSocket で構築したポートフォリオ作品 |
| Website | デプロイ URL（Railway の URL） |
| Topics | `laravel`, `vue3`, `typescript`, `inertia`, `websocket`, `portfolio` |

---

## 本番デプロイ時のよくある問題と対処法

### マイグレーションが失敗する

**原因：** 本番 DB への接続設定が間違っている  
**対処：** Railway の MySQL サービスの接続情報（HOST・PORT・PASSWORD）を正確に設定する

### ストレージの画像にアクセスできない

**原因：** `php artisan storage:link` が実行されていない  
**対処：** `nixpacks.toml` の `build` フェーズに `storage:link` を追加（今回実装済み）

### `APP_KEY` が設定されていない

**症状：** 500 エラー、セッションが使えない  
**対処：**
```bash
php artisan key:generate --show   # ローカルで生成してコピー
# Railway の Variables に APP_KEY として設定
```

### キューが処理されない（通知・メールが届かない）

**原因：** Railway はデフォルトで Web プロセスしか起動しない  
**対処：** `Procfile` を追加してワーカーを起動する

```
web: php artisan serve --host=0.0.0.0 --port=$PORT
worker: php artisan queue:work --sleep=3 --tries=3
```

---

## ファイル変更サマリー

| ファイル | 変更内容 |
|---------|---------|
| `nixpacks.toml` | 新規作成：Railway ビルド設定 |
| `.env.example` | 全変数をコメント付きで整備 |
| `README.md` | ポートフォリオ向けに全面リライト |

---

## 学習ポイントまとめ

- **`APP_DEBUG=false`** — 本番では必ず false にする。true のままだと例外のスタックトレースが画面に表示されてしまう
- **`nixpacks.toml`** — Railway のビルドプロセスをカスタマイズするファイル。PHP・Node.js のバージョン指定や Laravel のキャッシュ生成を自動化できる
- **`--no-dev --optimize-autoloader`** — 本番用 composer install のオプション。開発用パッケージを除外しオートローダーを最適化する
- **`migrate --force`** — 本番環境では確認プロンプトをスキップするために `--force` が必要
- **`.env.example`** — 機密値を含まない環境変数の一覧。チームメンバーや採用担当者への「設計書」として機能する
- **README の役割** — コードと同じくらい重要なポートフォリオの顔。機能・技術スタック・構築手順・アーキテクチャを簡潔にまとめる
