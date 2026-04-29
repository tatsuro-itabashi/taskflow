# Day 11 — 通知システム（Laravel Notification）

Laravel Notification クラスを使って、タスクアサイン時に担当者へ通知を送ります。  
「database チャンネル（アプリ内通知センター）」と「mail チャンネル（メール）」の両方を実装します。

---

## 今日のゴール

```
タスクに担当者をアサインする
         ↓
AssignedToTask 通知を dispatch
         ↓
┌──────────────────────────────┐
│  database チャンネル          │  → notifications テーブルに保存
│  （アプリ内通知センター）      │     → ベルアイコンで未読件数を表示
└──────────────────────────────┘
┌──────────────────────────────┐
│  mail チャンネル              │  → メールで通知
│  （メール通知）               │     → storage/logs/laravel.log で確認
└──────────────────────────────┘
```

---

## Notification と Event の違い

| 機能 | Event + Listener | Notification |
|------|-----------------|--------------|
| 目的 | システム内部の処理を連鎖させる | **特定のユーザー**へ情報を届ける |
| 送信先 | 複数の Listener | 特定の notifiable（User モデルなど） |
| チャンネル | なし（PHP の処理） | mail / database / slack / broadcast など |
| 向いている場面 | ログ記録、在庫更新など | メール送信、プッシュ通知、アプリ内通知 |

### チャンネルの種類

| チャンネル | 保存先 |
|-----------|--------|
| `database` | `notifications` テーブル |
| `mail` | メール（SMTP / log） |
| `broadcast` | WebSocket（Reverb）— Day 10 と組み合わせ可能 |
| `slack` | Slack webhook |

---

## PART 1 — notifications テーブルを作成する

```bash
php artisan notifications:table
php artisan migrate
```

### テーブル構造

```
notifications
├── id               (UUID, primary)
├── type             (通知クラスの FQCN)
├── notifiable_type  ┐ morphs('notifiable') で
├── notifiable_id    ┘ まとめて生成される
├── data             (JSON — 通知の内容)
├── read_at          (nullable — null = 未読)
├── created_at
└── updated_at
```

**`morphs('notifiable')` について：**  
マイグレーションでは `$table->morphs('notifiable')` と1行で記述されていますが、これは `notifiable_type`・`notifiable_id`・複合インデックスを一括生成するショートハンドです。

### User モデルに `Notifiable` トレイトを確認する

Breeze でデフォルト追加済みです：

```php
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable; // ← これがあれば OK
}
```

---

## PART 2 — AssignedToTask 通知クラスを作る

```bash
php artisan make:notification AssignedToTask
```

`app/Notifications/AssignedToTask.php`：

```php
<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssignedToTask extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Task $task,
        public readonly User $assignedBy,
    ) {}

    /**
     * 使用するチャンネルを返す
     * ['database'] のみ → DB 通知だけ
     * ['mail'] のみ    → メールだけ
     * 両方返す          → 両方送信
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * database チャンネル：notifications テーブルに保存する内容
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'task_id'     => $this->task->id,
            'task_title'  => $this->task->title,
            'project_id'  => $this->task->project_id,
            'assigned_by' => [
                'id'   => $this->assignedBy->id,
                'name' => $this->assignedBy->name,
            ],
        ];
    }

    /**
     * mail チャンネル：メールの内容
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = url("/workspaces/{$this->task->project->workspace_id}/projects/{$this->task->project_id}");

        return (new MailMessage)
            ->subject("タスク「{$this->task->title}」が割り当てられました")
            ->greeting("こんにちは、{$notifiable->name} さん")
            ->line("{$this->assignedBy->name} さんがタスクを割り当てました。")
            ->line("タスク名：**{$this->task->title}**")
            ->action('タスクを確認する', $url)
            ->line('ご確認よろしくお願いします。');
    }
}
```

### `via()` が通知の設計を決める

```php
// ユーザー設定に応じてチャンネルを動的に変える応用例
public function via(object $notifiable): array
{
    $channels = ['database']; // DB は常に保存

    if ($notifiable->email_notifications_enabled) {
        $channels[] = 'mail'; // メール通知が有効なユーザーにだけメール送信
    }

    return $channels;
}
```

---

## PART 3 — タスクアサイン時に通知を発火させる

`app/Http/Controllers/Api/TaskController.php` の `update()` に追記：

```php
use App\Notifications\AssignedToTask;
use Illuminate\Support\Facades\Notification;

public function update(Request $request, Project $project, Task $task): TaskResource
{
    $old = $task->getOriginal();

    $validated = $request->validate([/* ... */]);
    $task->update($validated);

    // Day 9：ActivityLog イベント
    TaskUpdated::dispatch($task, $request->user(), $changes);

    // Day 10：ステータス変更のブロードキャスト
    if (isset($validated['status']) && $old['status'] !== $validated['status']) {
        TaskStatusChanged::dispatch($task, $request->user(), $old['status']);
    }

    // Day 11：担当者が変更された場合に通知を送る
    if (isset($validated['assignee_id'])
        && $validated['assignee_id'] !== $old['assignee_id']
        && $validated['assignee_id'] !== null
    ) {
        $assignee = User::query()->find($validated['assignee_id']);

        // 自分自身をアサインした場合は通知しない
        if ($assignee && $assignee->id !== $request->user()->id) {
            Notification::send($assignee, new AssignedToTask($task, $request->user()));
        }
    }

    return new TaskResource($task);
}
```

### 通知の送信方法

```php
// モデルから直接送る（最もシンプル）
$user->notify(new AssignedToTask($task, $sender));

// Notification ファサードで送る（複数ユーザーへの一括送信に向く）
Notification::send($user, new AssignedToTask($task, $sender));
Notification::send($users, new AssignedToTask($task, $sender)); // コレクション可

// キューを使わず即時送信（テスト・デバッグ用）
$user->notifyNow(new AssignedToTask($task, $sender));
```

---

## PART 4 — API で未読通知を取得・既読にする

### `routes/api.php` に追加

```php
use App\Http\Controllers\Api\NotificationController;

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // 通知
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
});
```

### `app/Http/Controllers/Api/NotificationController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * 未読通知一覧を返す（最大20件）
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->unreadNotifications()
            ->latest()
            ->take(20)
            ->get()
            ->map(fn ($n) => [
                'id'         => $n->id,
                'data'       => $n->data,
                'created_at' => $n->created_at->diffForHumans(), // 例："3分前"
            ]);

        return response()->json([
            'notifications' => $notifications,
            'unread_count'  => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * 特定の通知を既読にする
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->findOrFail($id);

        $notification->markAsRead(); // read_at = now() をセット

        return response()->json(['message' => '既読にしました']);
    }

    /**
     * 全通知を既読にする
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => '全て既読にしました']);
    }
}
```

### Notifiable トレイトが提供するリレーション

```php
$user->notifications;           // 全通知
$user->unreadNotifications;     // 未読のみ（read_at が null）
$user->readNotifications;       // 既読のみ

$user->unreadNotifications()->count();               // 未読件数
$notification->markAsRead();                         // 1件を既読に
$user->unreadNotifications()->update(['read_at' => now()]); // 全件既読
```

---

## PART 5 — Vue で通知センター（ベルアイコン）を実装する

### `resources/js/Components/NotificationBell.vue`

```vue
<template>
    <div class="relative">
        <!-- ベルアイコン -->
        <button @click="toggleDropdown" class="relative p-2 text-gray-500 hover:text-gray-700">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>

            <!-- 未読バッジ -->
            <span
                v-if="unreadCount > 0"
                class="absolute top-1 right-1 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-xs text-white"
            >
                {{ unreadCount > 9 ? '9+' : unreadCount }}
            </span>
        </button>

        <!-- 通知ドロップダウン -->
        <div v-if="isOpen" class="absolute right-0 mt-2 w-80 rounded-lg border bg-white shadow-lg z-50">
            <div class="flex items-center justify-between border-b px-4 py-3">
                <span class="font-semibold text-sm">通知</span>
                <button v-if="unreadCount > 0" @click="markAllAsRead" class="text-xs text-blue-500 hover:underline">
                    全て既読にする
                </button>
            </div>

            <ul class="max-h-80 overflow-y-auto divide-y">
                <li
                    v-for="n in notifications"
                    :key="n.id"
                    @click="markAsRead(n.id)"
                    class="px-4 py-3 hover:bg-gray-50 cursor-pointer"
                >
                    <p class="text-sm text-gray-800">
                        <span class="font-medium">{{ n.data.assigned_by.name }}</span>
                        さんが「{{ n.data.task_title }}」を割り当てました
                    </p>
                    <p class="text-xs text-gray-400 mt-1">{{ n.created_at }}</p>
                </li>

                <li v-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">
                    未読通知はありません
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import axios from 'axios';

interface NotificationData {
    task_id: number;
    task_title: string;
    project_id: number;
    assigned_by: { id: number; name: string };
}

interface Notification {
    id: string;
    data: NotificationData;
    created_at: string;
}

const isOpen      = ref(false);
const notifications = ref<Notification[]>([]);
const unreadCount = ref(0);

async function fetchNotifications(): Promise<void> {
    const { data } = await axios.get('/api/v1/notifications');
    notifications.value = data.notifications;
    unreadCount.value   = data.unread_count;
}

async function markAsRead(id: string): Promise<void> {
    await axios.patch(`/api/v1/notifications/${id}/read`);
    notifications.value = notifications.value.filter(n => n.id !== id);
    unreadCount.value   = Math.max(0, unreadCount.value - 1);
}

async function markAllAsRead(): Promise<void> {
    await axios.patch('/api/v1/notifications/read-all');
    notifications.value = [];
    unreadCount.value   = 0;
}

function toggleDropdown(): void {
    isOpen.value = !isOpen.value;
    if (isOpen.value) fetchNotifications();
}

// 初回マウント時に未読件数を取得
onMounted(() => fetchNotifications());
</script>
```

### レイアウトに組み込む

`resources/js/Layouts/AuthenticatedLayout.vue` のナビゲーションバー内に追加：

```vue
<script setup lang="ts">
import NotificationBell from '@/Components/NotificationBell.vue';
</script>

<template>
    <!-- nav バーの右側あたりに配置 -->
    <NotificationBell />
</template>
```

---

## PART 6 — 動作確認

### ターミナルを3つ起動

```bash
php artisan serve      # ターミナル1
npm run dev            # ターミナル2
php artisan queue:work # ターミナル3（通知はキュー経由で送られるので必須）
```

### Tinker で通知をテストする

```bash
php artisan tinker
```

```php
$assignee = App\Models\User::find(1);
$sender   = App\Models\User::find(2);
$task     = App\Models\Task::with('project.workspace')->first();

// 通知を送信（queue:work が処理する）
$assignee->notify(new App\Notifications\AssignedToTask($task, $sender));

// キューを使わず即時送信（テスト用）
$assignee->notifyNow(new App\Notifications\AssignedToTask($task, $sender));

// notifications テーブルに保存されたか確認
$assignee->unreadNotifications()->count();   // → 1
$assignee->unreadNotifications()->first()->data;
```

### curl でエンドポイントをテストする

```bash
# トークン取得（OAuth ユーザーはパスワードなし → Tinker でトークンを直接発行）
php artisan tinker
>>> App\Models\User::find(1)->createToken('api-token')->plainTextToken

TOKEN="1|xxxxxxxxxxxxxxxx"

# 未読通知を取得（Accept ヘッダー必須）
curl -H "Authorization: Bearer $TOKEN" \
     -H "Accept: application/json" \
     http://localhost:8000/api/v1/notifications

# 全件既読にする
curl -X PATCH \
     -H "Authorization: Bearer $TOKEN" \
     -H "Accept: application/json" \
     http://localhost:8000/api/v1/notifications/read-all
```

> **`-H "Accept: application/json"` が必須な理由：**  
> このヘッダーがないと Laravel が「ブラウザからのリクエスト」と判断し、  
> 401 JSON でなく HTML リダイレクトを返してしまいます。

### メール通知をログで確認する

```bash
tail -f storage/logs/laravel.log | grep -A 20 "Subject:"
```

`Subject: タスク「...」が割り当てられました` が流れれば成功です。

---

## よくあるエラーと対処法

### 通知が届かない（テーブルに保存されない）

**原因1：** `notifications:table` → `migrate` を忘れている  
**原因2：** `queue:work` が起動していない  
**対処：** `notifyNow()` で即時テストして切り分ける

```php
$user->notifyNow(new AssignedToTask($task, $sender));
```

### curl が HTML を返す（ログインページにリダイレクト）

**原因：** `Accept: application/json` ヘッダーが不足している  
**対処：** `-H "Accept: application/json"` を追加する

### `diffForHumans()` が英語になる

**原因：** アプリのロケールが `en` のまま  
**対処：** `config/app.php` を修正

```php
'locale' => 'ja',
```

### OAuth ユーザーがトークン API を使えない

**原因：** OAuth ユーザーはパスワードが未設定のため `/api/tokens/create` でエラーになる  
**対処：** Tinker でトークンを直接発行する

```php
App\Models\User::find(1)->createToken('api-token')->plainTextToken
```

---

## ファイル変更サマリー

| ファイル | 変更内容 |
|---------|---------|
| `database/migrations/..._notifications_table.php` | 自動生成（`notifications:table` コマンド） |
| `app/Notifications/AssignedToTask.php` | 新規作成：database + mail チャンネルの通知クラス |
| `app/Http/Controllers/Api/TaskController.php` | `update()` にアサイン通知を追加 |
| `app/Http/Controllers/Api/NotificationController.php` | 新規作成：未読取得・既読処理 API |
| `routes/api.php` | 通知 API ルートを3本追加 |
| `resources/js/Components/NotificationBell.vue` | 新規作成：ベルアイコン + ドロップダウン（TypeScript） |
| `resources/js/Layouts/AuthenticatedLayout.vue` | `NotificationBell` を組み込む |

---

## 学習ポイントまとめ

- **Notification クラス** — 「誰に（notifiable）」「どのチャンネルで（via）」「何を届けるか（toDatabase / toMail）」を1クラスにまとめる
- **`via()`** — 返す配列を変えるだけでチャンネルを追加・削除できる。ユーザー設定に応じた条件分岐も可能
- **`database` チャンネル** — `notifications` テーブルに JSON で保存。`read_at` が null = 未読
- **`toDatabase()`** — フロントで使うデータだけを絞って返す（`toArray()` の別名的な役割）
- **`mail` チャンネル** — `MailMessage` の流暢な API で件名・本文・ボタンを設定できる
- **`unreadNotifications()`** — `Notifiable` トレイトが提供するリレーション。未読件数の取得に使う
- **`markAsRead()`** — `read_at` に現在時刻をセットするシンプルな実装
- **`Notification::send()`** — ファサード経由で送ると複数ユーザーへの一括送信が容易になる
- **`notifyNow()`** — キューを使わず即時実行。開発中のデバッグに便利
