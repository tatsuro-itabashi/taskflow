# TaskFlow — チーム向けタスク管理アプリ

> Laravel 12 + Vue 3 + TypeScript で構築した SaaS スタイルのプロジェクト管理ツール

[![Laravel](https://img.shields.io/badge/Laravel-12.x-red?logo=laravel)](https://laravel.com)
[![Vue.js](https://img.shields.io/badge/Vue.js-3.x-green?logo=vue.js)](https://vuejs.org)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.x-blue?logo=typescript)](https://typescriptlang.org)

---

## 📌 概要

TaskFlow は複数ワークスペース・複数プロジェクトを管理できるチーム向けタスク管理アプリです。
Laravel 12 の最新機能を活用しながら、ポートフォリオとして実装しました。

---

## ✨ 主な機能

| 機能 | 説明 |
|------|------|
| 🔐 認証 | メール認証 + GitHub OAuth（Socialite） |
| 🏢 ワークスペース | 複数ワークスペース・メンバー管理・役割（owner / admin / member） |
| 📋 プロジェクト管理 | CRUD・認可（Policy）・メンバー限定アクセス |
| ✅ タスク管理 | ステータス・優先度・担当者・ファイル添付 |
| ⚡ リアルタイム更新 | Laravel Reverb（WebSocket）でステータス変更を即時反映 |
| 🔔 通知システム | タスクアサイン時にアプリ内 + メール通知 |
| 📊 ダッシュボード | ワークスペース統計・進捗バー・アクティビティログ |
| 🚀 REST API | Sanctum 認証・レート制限・API Resource・Scramble ドキュメント |

---

## 🛠 技術スタック

### バックエンド
- **Laravel 12** — PHP フレームワーク
- **MySQL 8.0** — データベース
- **Laravel Reverb** — WebSocket サーバー
- **Laravel Sanctum** — API トークン認証
- **Laravel Socialite** — GitHub OAuth
- **Laravel Horizon**（予定） — キュー監視

### フロントエンド
- **Vue 3** + **TypeScript** — UI フレームワーク
- **Inertia.js** — SPA 的なページ遷移
- **Tailwind CSS** — スタイリング
- **@laravel/echo-vue** — WebSocket クライアント

### インフラ・ツール
- **Railway** — クラウドホスティング
- **Redis** — キャッシュ・セッション・キュー
- **Laravel Pint** — コードフォーマット
- **Larastan（PHPStan）** — 静的解析（Level 5）
- **Pest** — テストフレームワーク

---

## 📐 アーキテクチャのポイント

```
HTTP Request
    ↓
Middleware（auth:sanctum / throttle:api）
    ↓
Controller（FormRequest バリデーション）
    ↓
Policy（Role ベースの認可）
    ↓
Eloquent（eager loading で N+1 防止）
    ↓
Event dispatch → Listener（ActivityLog）
    ↓
Observer（position 自動付与 / Cache クリア）
    ↓
Broadcast → Reverb → Vue（リアルタイム反映）
    ↓
Notification → DB + Mail（アサイン通知）
```

---

## 🚀 ローカル環境構築

```bash
# 1. リポジトリをクローン
git clone https://github.com/your-name/taskflow.git
cd taskflow

# 2. 依存パッケージをインストール
composer install
npm install

# 3. 環境変数を設定
cp .env.example .env
php artisan key:generate

# 4. データベースを設定（.env を編集）
php artisan migrate
php artisan db:seed

# 5. ストレージのリンク
php artisan storage:link

# 6. 起動（ターミナルを4つ用意）
php artisan serve       # PHP サーバー
npm run dev             # Vite
php artisan queue:work  # キューワーカー
php artisan reverb:start # WebSocket サーバー
```

---

## 🧪 テスト

```bash
# 全テストを実行
php artisan test

# カバレッジレポート付き
php artisan test --coverage
```

---

## 📄 API ドキュメント

Scramble による自動生成ドキュメント：

```
http://localhost:8000/docs/api
```

---

## 📝 開発ログ

2週間の学習記録を `docs/` に日別でまとめています。

| Day | テーマ |
|-----|--------|
| Day 1 | 環境構築・DB 設計 |
| Day 2 | 認証（Breeze + GitHub OAuth） |
| Day 3 | Workspace・メンバー管理 |
| Day 4 | Policy・認可 |
| Day 5 | Storage・ファイルアップロード |
| Day 6 | REST API・Sanctum |
| Day 7 | テスト（Pest） |
| Day 8 | Queue・Jobs・非同期処理 |
| Day 9 | Events・Listeners・Observer |
| Day 10 | リアルタイム通信（Reverb） |
| Day 11 | 通知システム |
| Day 12 | キャッシュ＆パフォーマンス |
| Day 13 | コード品質＆セキュリティ |
| Day 14 | 仕上げ |
