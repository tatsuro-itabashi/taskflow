# TaskFlow — チームプロジェクト管理 SaaS

> Laravel 12 ハンズオン学習で構築する、ポートフォリオ向けWebアプリケーション

---

## アプリケーション概要

**TaskFlow** は、チームがプロジェクトとタスクをリアルタイムで管理できる SaaS 型のプロジェクト管理ツールです。  
「シンプルさ」と「実用性」を両立させた設計で、個人開発者からスモールチームまでを対象にしています。

---

## 要件定義

### ターゲットユーザー

- スモールチーム（2〜10名）のエンジニア・クリエイター
- タスク管理を手軽に始めたい個人開発者

### 機能要件

#### 認証・ユーザー管理
- [ ] メールアドレス＋パスワードによる会員登録・ログイン
- [ ] Google / GitHub OAuth ログイン（Laravel Socialite）
- [ ] プロフィール編集（名前・アバター画像）
- [ ] パスワードリセット（メール通知）
- [ ] メール認証（登録後の確認メール）

#### ワークスペース（マルチテナント）
- [ ] ワークスペースの作成・編集・削除
- [ ] メンバー招待（メールリンク）
- [ ] ロール管理（Owner / Admin / Member）
- [ ] ワークスペースの切り替え

#### プロジェクト管理
- [ ] プロジェクトの CRUD（作成・表示・更新・削除）
- [ ] プロジェクトカラー・アイコン設定
- [ ] プロジェクトへのメンバーアサイン
- [ ] プロジェクト一覧（グリッド / リスト表示切り替え）

#### タスク管理
- [ ] タスクの CRUD
- [ ] ステータス管理（Todo / In Progress / In Review / Done）
- [ ] カンバンボード表示（ドラッグ&ドロップ）
- [ ] 優先度設定（Low / Medium / High / Urgent）
- [ ] 担当者アサイン・期日設定
- [ ] ラベル（タグ）の付与
- [ ] タスクへのコメント投稿
- [ ] ファイル添付（画像・PDF など）

#### リアルタイム機能
- [ ] タスク更新のリアルタイム反映（Laravel Reverb）
- [ ] 新着コメントのリアルタイム通知
- [ ] オンラインメンバーの表示

#### 通知
- [ ] アプリ内通知（ベルアイコン）
- [ ] メール通知（タスクのアサイン・期日リマインダー）
- [ ] 通知設定（ON/OFF の個別管理）

#### ダッシュボード & 分析
- [ ] 自分に割り当てられたタスク一覧
- [ ] プロジェクト進捗サマリー（完了率グラフ）
- [ ] 期日超過タスクのアラート表示
- [ ] 直近のアクティビティログ

#### REST API
- [ ] API 認証（Sanctum トークン）
- [ ] タスク・プロジェクトの CRUD エンドポイント
- [ ] API ドキュメント（Scramble による自動生成）

### 非機能要件

| 項目 | 内容 |
|------|------|
| レスポンスタイム | ページ初期表示 2 秒以内 |
| セキュリティ | CSRF 対策・XSS 対策・Rate Limiting |
| テスト | Feature / Unit テストのカバレッジ 70% 以上 |
| コード品質 | PHP CS Fixer + Larastan（静的解析）導入 |
| デプロイ | Render / Railway などで本番公開 |

---

## 技術スタック

### バックエンド
| 技術 | バージョン | 用途 |
|------|----------|------|
| PHP | 8.3 | 実行環境 |
| Laravel | 12.x | フレームワーク |
| Laravel Breeze | 2.x | 認証スキャフォールド |
| Laravel Socialite | 5.x | OAuth |
| Laravel Reverb | 1.x | WebSocket サーバー |
| Laravel Horizon | 5.x | キュー監視 UI |
| Laravel Sanctum | 4.x | API 認証 |
| Scramble | 0.x | API ドキュメント自動生成 |

### フロントエンド
| 技術 | バージョン | 用途 |
|------|----------|------|
| Vue.js | 3.x | UI フレームワーク |
| Inertia.js | 2.x | SPA 風 UX（API 不要） |
| TypeScript | 5.x | 型安全 |
| Tailwind CSS | 4.x | スタイリング |
| shadcn-vue | 最新 | UI コンポーネント |
| VueDraggable | 4.x | カンバンのドラッグ&ドロップ |
| Chart.js | 4.x | 分析グラフ |

### インフラ・開発環境
| 技術 | 用途 |
|------|------|
| Laravel Herd / Sail | ローカル開発環境 |
| MySQL 8.0 | データベース |
| Redis | キャッシュ・キュー・セッション |
| Vite | アセットバンドル |
| Pest | テストフレームワーク |

---

## データベース設計（主要テーブル）

```
users
├── id, name, email, password, avatar
└── email_verified_at, created_at

workspaces
├── id, name, slug, owner_id
└── created_at

workspace_user（中間テーブル）
├── workspace_id, user_id, role
└── created_at

workspace_invitations
├── id, workspace_id, email, token, role
└── expires_at

projects
├── id, workspace_id, name, description
├── color, icon, status
└── created_at

project_user（中間テーブル）
└── project_id, user_id

tasks
├── id, project_id, assignee_id, created_by
├── title, description, status, priority
├── due_date, position（カンバン順序）
└── created_at

task_labels（中間テーブル）
└── task_id, label_id

labels
└── id, workspace_id, name, color

comments
└── id, task_id, user_id, body, created_at

attachments
└── id, task_id, user_id, filename, path, size

notifications
└── id, user_id, type, data, read_at

activity_logs
└── id, workspace_id, user_id, subject_type, subject_id, action, created_at
```

---

## 2週間学習計画

### Week 1：Laravel 12 基礎 + コア機能の実装

| Day | テーマ | 学習内容 | 実装する機能 |
|-----|--------|---------|------------|
| Day 1 | **環境構築 & Laravel 12 基礎** | Herd/Sail セットアップ、ディレクトリ構成、ルーティング、ミドルウェア | プロジェクト作成、DB 接続、初期マイグレーション |
| Day 2 | **認証実装** | Breeze + Inertia + Vue、Sanctum、Socialite、メール認証 | 会員登録・ログイン・OAuth |
| Day 3 | **Eloquent ORM 深掘り** | マイグレーション、リレーション、スコープ、ファクトリ、シーダー | ワークスペース CRUD、メンバー招待 |
| Day 4 | **コントローラ・フォームリクエスト** | RESTful 設計、FormRequest バリデーション、ポリシー（認可） | プロジェクト CRUD + ロールベース認可 |
| Day 5 | **ファイル管理** | Storage ファサード、S3 互換ストレージ、画像リサイズ | タスクへのファイル添付・アバター画像アップロード |
| Day 6 | **REST API 設計** | API Resource、Sanctum トークン、Rate Limiting、Scramble | タスク CRUD の API エンドポイント + ドキュメント |
| Day 7 | **テスト入門（Pest）** | Feature / Unit テスト、RefreshDatabase、Mock、Factory | 認証・プロジェクト作成のテストケース実装 |

### Week 2：Laravel 12 応用 + 仕上げ

| Day | テーマ | 学習内容 | 実装する機能 |
|-----|--------|---------|------------|
| Day 8 | **Queue & Jobs** | キュー設定、Job クラス、Redis ドライバー、Horizon | 招待メール・通知メールの非同期送信 |
| Day 9 | **Events & Listeners & Observer** | イベント駆動設計、Observer パターン | タスク更新イベント → アクティビティログ自動記録 |
| Day 10 | **リアルタイム通信（Reverb）** | WebSocket、Broadcasting、Echo.js | タスク更新・コメントのリアルタイム反映 |
| Day 11 | **通知システム** | Notification クラス、メール・DB チャンネル、カスタム通知 | アプリ内通知 + メール通知の実装 |
| Day 12 | **キャッシュ & パフォーマンス** | Cache ファサード、クエリ最適化、N+1 問題、Eager Loading | ダッシュボード・分析機能の実装と高速化 |
| Day 13 | **コード品質 & セキュリティ** | Larastan、PHP CS Fixer、CSRF/XSS 対策、テスト拡充 | 静的解析クリア、テストカバレッジ向上 |
| Day 14 | **デプロイ & 仕上げ** | 本番環境設定、.env 管理、デプロイ手順、README 整備 | 本番公開・ポートフォリオ掲載用ドキュメント整備 |

---

## 学習の進め方

1. **毎日このリポジトリで課題をこなす** — AI コーチから当日の課題が提示されます
2. **手を動かしながら学ぶ** — コピペより自分で書くことを優先
3. **詰まったらまず公式ドキュメントを参照** — [Laravel 12 公式](https://laravel.com/docs/12.x)
4. **コミット習慣をつける** — 1 機能 1 コミットを目標に

---

## 完成イメージ

```
/ (ランディングページ)
/register, /login (認証)
/dashboard (マイタスク・進捗サマリー)
/workspaces/{slug} (ワークスペース概要)
/workspaces/{slug}/projects (プロジェクト一覧)
/workspaces/{slug}/projects/{id} (カンバンボード)
/workspaces/{slug}/settings (メンバー管理・設定)
/notifications (通知一覧)
/api/v1/... (REST API)
/horizon (キュー監視 ※ Admin のみ)
/docs/api (API ドキュメント)
```

---

## セットアップ手順（Day 1 で実施）

```bash
# Laravel 12 プロジェクト作成
composer create-project laravel/laravel taskflow

cd taskflow

# Breeze (Vue + Inertia + TypeScript) のインストール
composer require laravel/breeze --dev
php artisan breeze:install vue --typescript --pest

# 依存パッケージインストール
npm install

# 環境設定
cp .env.example .env
php artisan key:generate

# DB マイグレーション
php artisan migrate --seed

# 開発サーバー起動
php artisan serve
npm run dev
```

---

*このドキュメントは学習の進捗に合わせて随時更新されます。*
