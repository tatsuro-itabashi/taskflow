# Day 8 — Queue・Jobs・非同期処理

Laravel 12 の Queue と Job を使って、ワークスペース招待メールを非同期送信する手順です。Mailable によるメールテンプレート作成から、Job の実装・キューワーカーの起動・Horizon による監視まで実装します。

---

## 前提条件

- Day 7 までの実装が完了している
- `jobs` テーブルが作成済み（`0001_01_01_000002_create_jobs_table` が Ran 状態）

---

## なぜ Queue が必要か

```
Queue なし（同期処理）
  ユーザーが「招待する」をクリック
       ↓
  メール送信（外部 SMTP と通信）← 2〜5秒かかる
       ↓
  ようやくレスポンスが返る    ← ユーザーは待たされる

Queue あり（非同期処理）
  ユーザーが「招待する」をクリック
       ↓
  Job を jobs テーブルに積む  ← 一瞬で完了
       ↓
  即レスポンスが返る          ← ユーザーは待たない
       ↓
  バックグラウンドのワーカーが Job を取り出してメール送信
```

**Queue が活躍する場面：**
- メール送信（招待・通知・パスワードリセット）
- 画像リサイズ・PDF 生成
- 外部 API 呼び出し
- 大量データのインポート・エクスポート

---

## PART 1 — Queue の設定

### キュードライバーの種類

| ドライバー | 保存先 | 用途 |
|-----------|--------|------|
| `sync` | なし（即時実行） | テスト・デバッグ |
| `database` | `jobs` テーブル | 開発・小規模本番 |
| `redis` | Redis | 本番・高スループット |
| `sqs` | AWS SQS | 大規模本番 |

### `.env` の設定

```dotenv
# 開発中は database ドライバーで OK
QUEUE_CONNECTION=database

# Redis を使う場合（Horizon 必須）
# QUEUE_CONNECTION=redis

# メールはログに出力（開発用）
MAIL_MAILER=log
```

---

## PART 2 — Mailable でメールテンプレートを作る

Mailable はメールの「内容・件名・テンプレート」をクラスとして定義する仕組みです。

```bash
php artisan make:mail WorkspaceInvitationMail --markdown=emails.workspace.invitation
```

`app/Mail/WorkspaceInvitationMail.php`：

```php
<?php

namespace App\Mail;

use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkspaceInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly WorkspaceInvitation $invitation,
        public readonly Workspace $workspace,
    ) {}

    /** メールの件名・送信者など */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "「{$this->workspace->name}」への招待",
        );
    }

    /** メールの本文（Markdown テンプレートを指定） */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.workspace.invitation',
            with: [
                'inviteUrl'     => url("/invitations/{$this->invitation->token}/accept"),
                'workspaceName' => $this->workspace->name,
                'expiresAt'     => $this->invitation->expires_at->format('Y年m月d日'),
            ],
        );
    }
}
```

**`implements ShouldQueue` の意味**  
このインターフェースを実装すると `Mail::send()` でなく `Mail::queue()` と同等の動作になります。Job クラスから呼んだときに自動でキュー経由になります。

### メールテンプレート（Markdown）

`resources/views/emails/workspace/invitation.blade.php`：

```blade
@component('mail::message')
# ワークスペースへの招待

**{{ $workspaceName }}** にあなたを招待しました。

以下のボタンをクリックして参加してください。

@component('mail::button', ['url' => $inviteUrl, 'color' => 'blue'])
招待を承認する
@endcomponent

このリンクは **{{ $expiresAt }}** まで有効です。

心当たりがない場合は、このメールを無視してください。

Thanks,<br>
{{ config('app.name') }}
@endcomponent
```

---

## PART 3 — Job クラスで非同期処理を実装する

```bash
php artisan make:job SendWorkspaceInvitationJob
```

`app/Jobs/SendWorkspaceInvitationJob.php`：

```php
<?php

namespace App\Jobs;

use App\Mail\WorkspaceInvitationMail;
use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWorkspaceInvitationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;   // 失敗時のリトライ回数
    public int $timeout = 30; // タイムアウト秒数

    public function __construct(
        public readonly WorkspaceInvitation $invitation,
    ) {}

    /**
     * Job の実際の処理
     */
    public function handle(): void
    {
        // 処理前に有効期限を再チェック（キューに積んでから時間が経つ場合がある）
        if ($this->invitation->isExpired()) {
            return;
        }

        Mail::to($this->invitation->email)
            ->send(new WorkspaceInvitationMail(
                invitation: $this->invitation,
                workspace: $this->invitation->workspace,
            ));
    }

    /**
     * リトライ上限を超えて失敗したときの処理
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("招待メール送信失敗: {$this->invitation->email}", [
            'error' => $exception->getMessage(),
        ]);
    }
}
```

### Job で使うトレイトの役割

| トレイト | 役割 |
|---------|------|
| `Dispatchable` | `Job::dispatch()` を使えるようにする |
| `InteractsWithQueue` | `$this->release()` / `$this->fail()` などキュー操作を可能にする |
| `Queueable` | キュー名・接続・遅延時間などを設定できるようにする |
| `SerializesModels` | モデルを ID のみ保存し、実行時に再取得（メモリ効率・整合性） |

**`SerializesModels` の重要性**  
モデルを丸ごとシリアライズするとデータが古くなる問題があります。このトレイトにより ID だけ保存し、Job 実行時に最新データを DB から取得します。

---

## PART 4 — Controller で Job を dispatch する

```bash
php artisan make:controller WorkspaceInvitationController
```

`app/Http/Controllers/WorkspaceInvitationController.php`：

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\SendWorkspaceInvitationJob;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkspaceInvitationController extends Controller
{
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'role'  => ['required', 'in:admin,member'],
        ]);

        // 有効な招待がすでに存在するかチェック
        $exists = $workspace->invitations()
            ->where('email', $request->email)
            ->where('expires_at', '>', now())
            ->exists();

        if ($exists) {
            return back()->withErrors(['email' => 'すでに招待済みです']);
        }

        // 招待レコードを作成
        $invitation = $workspace->invitations()->create([
            'email'      => $request->email,
            'role'       => $request->role,
            'token'      => Str::random(32),
            'expires_at' => now()->addDays(7),
        ]);

        // Job をキューに積む（この行が返った時点でユーザーへのレスポンスは完了）
        SendWorkspaceInvitationJob::dispatch($invitation);

        return back()->with('success', "{$request->email} に招待メールを送信しました");
    }
}
```

### dispatch の種類

```php
// キューに積む（非同期・本番推奨）
SendWorkspaceInvitationJob::dispatch($invitation);

// 即時実行（同期・テスト・デバッグ用）
SendWorkspaceInvitationJob::dispatchSync($invitation);

// 遅延実行（5分後に処理）
SendWorkspaceInvitationJob::dispatch($invitation)->delay(now()->addMinutes(5));

// 特定のキューに積む
SendWorkspaceInvitationJob::dispatch($invitation)->onQueue('emails');
```

### ルートを追加する

`routes/web.php` に追記：

```php
use App\Http\Controllers\WorkspaceInvitationController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'store'])
        ->name('workspaces.invitations.store');
});
```

---

## PART 5 — キューワーカーを起動して動作確認する

### ターミナルを3つ用意する

```bash
# ターミナル1：PHP サーバー
php artisan serve

# ターミナル2：Vite
npm run dev

# ターミナル3：キューワーカー（常駐プロセス）
php artisan queue:work
```

### Tinker で Job を手動 dispatch する

```bash
php artisan tinker
```

```php
// テスト用の招待を作成
$workspace = App\Models\Workspace::first()

$invitation = $workspace->invitations()->create([
    'email'      => 'test@example.com',
    'role'       => 'member',
    'token'      => Str::random(32),
    'expires_at' => now()->addDays(7),
])

// Job をキューに積む
App\Jobs\SendWorkspaceInvitationJob::dispatch($invitation)

// jobs テーブルに積まれたか確認
DB::table('jobs')->count()  // → 1
```

ワーカーターミナルに以下が流れれば成功：

```
[2026-04-27] Processing: App\Jobs\SendWorkspaceInvitationJob
[2026-04-27] Processed:  App\Jobs\SendWorkspaceInvitationJob
```

### メール内容をログで確認する

```bash
tail -f storage/logs/laravel.log | grep -A 20 "Subject:"
```

---

## PART 6 — 失敗 Job の扱い方

```bash
# 失敗した Job の一覧を確認
php artisan queue:failed

# 全件リトライ
php artisan queue:retry all

# 特定 ID を再実行
php artisan queue:retry 5

# 失敗 Job をすべて削除
php artisan queue:flush
```

---

## PART 7 — Horizon のセットアップ（Redis 版・発展）

Horizon は Queue を **グラフィカルに監視** できる Laravel 公式ツールです。

### Redis を起動する

```bash
brew install redis
brew services start redis
redis-cli ping  # → PONG
```

### Horizon をインストール

```bash
composer require laravel/horizon
php artisan horizon:install
```

### `.env` を Redis に切り替える

```dotenv
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

### Horizon を起動する

```bash
# queue:work の代わりに horizon を使う
php artisan horizon
```

ブラウザで確認：

```
http://localhost:8000/horizon
```

処理中の Job・失敗・スループット・待機時間がリアルタイムで確認できます。

---

## ファイル変更サマリー

| ファイル | 変更内容 |
|---------|---------|
| `app/Mail/WorkspaceInvitationMail.php` | 新規作成：招待メールの Mailable クラス |
| `resources/views/emails/workspace/invitation.blade.php` | 新規作成：Markdown メールテンプレート |
| `app/Jobs/SendWorkspaceInvitationJob.php` | 新規作成：招待メール非同期送信 Job |
| `app/Http/Controllers/WorkspaceInvitationController.php` | 新規作成：招待送信コントローラ |
| `routes/web.php` | 招待送信ルートを追加 |
| `.env` | `QUEUE_CONNECTION` を設定 |

---

## よくあるエラーと対処法

### Job が処理されない（ワーカーが動いていない）

**原因：** `php artisan queue:work` を実行していない。

**確認：**
```bash
# jobs テーブルにたまっていないか確認
php artisan tinker
>>> DB::table('jobs')->count()

# ワーカーを起動
php artisan queue:work
```

### `SerializesModels` を使っているのにモデルが見つからない

**原因：** Job を dispatch してからワーカーが処理するまでの間に対象レコードが削除された。

**対処：** `handle()` 内でモデルの存在を確認する。

```php
public function handle(): void
{
    // refresh() で最新状態を再取得（削除済みなら null）
    $invitation = $this->invitation->refresh();
    if (! $invitation || $invitation->isExpired()) {
        return;
    }
    // ...
}
```

### メールが送信されない（ログにも出ない）

**原因：** `MAIL_MAILER=log` になっていない、またはワーカーが起動していない。

**対処：**
```dotenv
MAIL_MAILER=log
```
```bash
php artisan config:clear
php artisan queue:work
```

---

## 学習ポイントまとめ

- **Queue** — 時間のかかる処理をバックグラウンドに回すことでレスポンスを高速化する
- **`ShouldQueue`** — このインターフェースを実装するだけでキュー対応になる
- **`SerializesModels`** — モデルを ID のみ保存し、実行時に最新データを再取得する。古いデータの参照を防ぐ
- **`dispatch()`** — 非同期でキューに積む。`dispatchSync()` でテスト時に即時実行できる
- **`$tries`** — 失敗時のリトライ回数。メール送信のような一時的なエラーに対応できる
- **`failed()`** — リトライ上限を超えた際に呼ばれる。ログ記録・アラート送信などに使う
- **`queue:work`** — キューワーカー。常駐プロセスとして起動する
- **Horizon** — Redis + GUI でキューをリアルタイム監視。本番環境での必須ツール
