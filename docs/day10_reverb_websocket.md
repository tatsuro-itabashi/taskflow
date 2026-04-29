# Day 10 — リアルタイム通信（Laravel Reverb / WebSockets）

Laravel Reverb を使って WebSocket サーバーを立て、タスクのステータス変更をワークスペース全員にリアルタイムで反映させます。  
フロントエンドは `@laravel/echo-vue` v2 の `useEcho` コンポーザブルで TypeScript 対応します。

---

## 今日のゴール

```
誰かがタスクのステータスを変更する
         ↓
API Controller が TaskStatusChanged イベントを dispatch
         ↓
Reverb（WebSocket サーバー）がワークスペース全メンバーにブロードキャスト
         ↓
他のメンバーのブラウザが即座に画面を更新（ページリロード不要）
```

---

## PART 1 — Reverb インストール＆設定

### Reverb とは

Laravel 公式の WebSocket サーバー（Laravel 11 で導入）。  
Pusher 互換プロトコルを使うため、フロントは **pusher-js** クライアントで動きます。

### インストール

```bash
php artisan install:broadcasting
```

このコマンドが以下を自動で行います：

- `laravel/reverb` パッケージのインストール
- `config/broadcasting.php` の生成
- `routes/channels.php` の生成
- `.env` への Reverb 設定の追加
- `@laravel/echo-vue` の設定を `app.ts` に追記

### `.env` の設定値

```dotenv
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=*****        # 自動生成（公開しない）
REVERB_APP_KEY=*****       # 自動生成（公開しない）
REVERB_APP_SECRET=*****    # 自動生成（公開しない）
REVERB_HOST="localhost"
REVERB_PORT=8080
REVERB_SCHEME=http

# Vite 経由でフロントに渡す値
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

---

## PART 2 — Broadcastable Event を作る

### `app/Events/TaskStatusChanged.php`

```bash
php artisan make:event TaskStatusChanged
```

```php
<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly User $changedBy,
        public readonly string $oldStatus,
    ) {}

    /**
     * どのチャンネルにブロードキャストするか
     * PrivateChannel = 認証済みユーザーのみ受信可能
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("workspace.{$this->task->project->workspace_id}"),
        ];
    }

    /**
     * フロントエンドに送るデータ（public プロパティ全体でなく明示的に絞る）
     */
    public function broadcastWith(): array
    {
        return [
            'task' => [
                'id'         => $this->task->id,
                'title'      => $this->task->title,
                'status'     => $this->task->status,
                'old_status' => $this->oldStatus,
            ],
            'changed_by' => [
                'id'   => $this->changedBy->id,
                'name' => $this->changedBy->name,
            ],
        ];
    }

    /**
     * フロントで受け取るイベント名（デフォルトは FQCN になる）
     */
    public function broadcastAs(): string
    {
        return 'task.status.changed';
    }
}
```

### `ShouldBroadcast` の主要メソッド

| メソッド | 役割 |
|---------|------|
| `broadcastOn()` | 送信先チャンネルを配列で返す |
| `broadcastWith()` | フロントに届くペイロード（省略すると public プロパティ全体） |
| `broadcastAs()` | フロントで `listen` するイベント名。カスタム名は先頭に `.` を付けて受信 |

### Controller で dispatch する

`app/Http/Controllers/Api/TaskController.php` の `update()` にブロードキャストを追加：

```php
use App\Events\TaskStatusChanged;

public function update(Request $request, Project $project, Task $task): TaskResource
{
    $old = $task->getOriginal(); // 変更前の値を保持

    $validated = $request->validate([
        'title'       => ['sometimes', 'string', 'max:255'],
        'status'      => ['sometimes', 'in:todo,in_progress,in_review,done'],
        // ...
    ]);

    $task->update($validated);

    // 既存の ActivityLog イベント（Day 9 から）
    TaskUpdated::dispatch($task, $request->user(), $changes);

    // ステータスが変わった場合のみブロードキャスト
    if (isset($validated['status']) && $old['status'] !== $validated['status']) {
        TaskStatusChanged::dispatch($task, $request->user(), $old['status']);
    }

    return new TaskResource($task);
}
```

**ポイント：** ステータス変更の場合のみ dispatch することで、無駄な WebSocket 送信を防いでいます。

---

## PART 3 — Private Channel 認可

PrivateChannel は「誰がこのチャンネルを購読できるか」をサーバー側で明示的に制御します。

### `routes/channels.php`

```php
<?php

use App\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('workspace.{workspaceId}', function ($user, int $workspaceId) {
    $workspace = Workspace::query()->find($workspaceId);

    if (! $workspace) {
        return false;
    }

    // オーナーまたはワークスペースのメンバーなら購読を許可
    return $workspace->members()->where('user_id', $user->id)->exists()
        || $workspace->owner_id === $user->id;
});
```

**`owner_id` のチェックが重要な理由：**  
アプリ経由でワークスペースを作成した場合、オーナーが `workspace_user` テーブルに自動追加されません。  
`owner_id` を直接チェックすることで、この状況でも正しく認可できます。

### チャンネルの種類

| チャンネル | 認証 | 用途 |
|-----------|------|------|
| `Channel` | 不要（誰でも購読可） | 公開情報 |
| `PrivateChannel` | 必要（サーバーが認可） | チームの内部情報 |
| `PresenceChannel` | 必要 + メンバー一覧取得可 | オンライン状態表示 |

---

## PART 4 — フロントエンド TypeScript セットアップ

### `resources/js/app.ts` — Reverb 接続設定

`install:broadcasting` で自動追加された `configureEcho` に env 変数を設定します：

```typescript
import { configureEcho } from '@laravel/echo-vue';

configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

### `resources/js/types/vite-env.d.ts` — 環境変数の型定義

`import.meta.env.VITE_REVERB_*` を TypeScript が認識できるよう追加：

```typescript
/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly VITE_APP_NAME: string;
    readonly VITE_REVERB_APP_KEY: string;
    readonly VITE_REVERB_HOST: string;
    readonly VITE_REVERB_PORT: string;
    readonly VITE_REVERB_SCHEME: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
```

### `resources/js/types/index.d.ts` — ブロードキャストペイロード型

```typescript
// ブロードキャストイベントのペイロード型
export interface TaskStatusChangedPayload {
    task: {
        id: number;
        title: string;
        status: string;
        old_status: string;
    };
    changed_by: {
        id: number;
        name: string;
    };
}
```

---

## PART 5 — Vue コンポーネントでリアルタイム反映

### `@laravel/echo-vue` の `useEcho` コンポーザブル

`window.Echo` を直接使う古い方法と、`@laravel/echo-vue` v2 の新しい方法の比較：

| 項目 | 旧（`window.Echo`） | 新（`useEcho`） |
|------|---------------------|----------------|
| 購読 | `window.Echo.private('...')` | `const { stopListening } = useEcho(...)` |
| 解除 | `window.Echo.leave('...')` | `stopListening()` |
| 型安全 | なし | ジェネリクスで型引数を渡せる |
| Vue 統合 | 手動 | コンポーザブルとして自然に使える |

### `resources/js/Pages/Projects/Show.vue`

```vue
<template>
    <div class="p-6">
        <h1 class="text-2xl font-bold mb-4">{{ project.name }}</h1>

        <!-- リアルタイム通知バナー -->
        <Transition
            enter-active-class="transition ease-out duration-300"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="notification"
                class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded mb-4"
            >
                {{ notification }}
            </div>
        </Transition>

        <!-- タスク一覧 -->
        <div class="space-y-2">
            <div
                v-for="task in localTasks"
                :key="task.id"
                class="flex items-center justify-between border rounded p-3 bg-white shadow-sm"
            >
                <span class="font-medium">{{ task.title }}</span>
                <span class="text-xs px-2 py-1 rounded" :class="statusClass(task.status)">
                    {{ task.status }}
                </span>
            </div>

            <p v-if="localTasks.length === 0" class="text-gray-400 text-sm">
                タスクがありません
            </p>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onUnmounted } from 'vue';
import { useEcho } from '@laravel/echo-vue';
import type { TaskStatusChangedPayload } from '@/types';

interface Task {
    id: number;
    title: string;
    status: string;
}

interface Project {
    id: number;
    name: string;
    workspace_id: number;
}

const props = defineProps<{
    project: Project;
    tasks: Task[];
}>();

// ローカルにタスクを持つ（リアルタイム更新で書き換える）
const localTasks = ref<Task[]>([...props.tasks]);
const notification = ref<string | null>(null);
let notificationTimer: ReturnType<typeof setTimeout> | null = null;

function showNotification(message: string): void {
    notification.value = message;
    if (notificationTimer) clearTimeout(notificationTimer);
    notificationTimer = setTimeout(() => { notification.value = null; }, 4000);
}

function statusClass(status: string): string {
    const map: Record<string, string> = {
        todo:        'bg-gray-100 text-gray-600',
        in_progress: 'bg-yellow-100 text-yellow-700',
        done:        'bg-green-100 text-green-700',
    };
    return map[status] ?? 'bg-gray-100 text-gray-500';
}

// Private チャンネルを購読し、イベントを受信する
// useEcho<ペイロード型>(チャンネル名, イベント名, コールバック)
const { stopListening } = useEcho<TaskStatusChangedPayload>(
    `workspace.${props.project.workspace_id}`,
    '.task.status.changed',
    (e) => {
        // ローカルのタスク一覧を更新
        const target = localTasks.value.find(t => t.id === e.task.id);
        if (target) {
            target.status = e.task.status;
        }
        showNotification(
            `${e.changed_by.name} が「${e.task.title}」を ${e.task.old_status} → ${e.task.status} に変更しました`,
        );
    },
);

// コンポーネント破棄時に購読解除（メモリリーク防止）
onUnmounted(() => {
    stopListening();
    if (notificationTimer) clearTimeout(notificationTimer);
});
</script>
```

**`.task.status.changed` の先頭 `.` について：**  
`broadcastAs()` でカスタム名を定義した場合、フロントでは先頭に `.` を付けるのが Laravel Echo のルールです。

---

## PART 6 — ProjectController / Policy の追加実装

### `app/Http/Controllers/ProjectController.php` — `show()` を実装

```php
public function show(Workspace $workspace, Project $project): Response
{
    $this->authorize('view', $project);

    $tasks = $project->tasks()
        ->with('assignee')
        ->orderBy('position')
        ->get(['id', 'title', 'status', 'priority', 'position', 'assignee_id']);

    return Inertia::render('Projects/Show', [
        'project' => [
            'id'           => $project->id,
            'name'         => $project->name,
            'workspace_id' => $project->workspace_id,
        ],
        'tasks' => $tasks,
    ]);
}
```

`routes/web.php` に `show` を追加：

```php
Route::resource('workspaces.projects', ProjectController::class)
    ->only(['index', 'show', 'store', 'update', 'destroy']);
```

### `app/Policies/ProjectPolicy.php` — `getRole()` を修正

**問題：** アプリ UI 経由でワークスペースを作成した場合、オーナーが `workspace_user` テーブルに自動追加されない → `getRole()` が `null` を返して 403 になる。

**修正：** `owner_id` を先にチェックすることで、どちらの作成方法でも正しく認可する：

```php
private function getRole(User $user, Workspace $workspace): ?string
{
    // workspace の owner は常に 'owner' として扱う
    if ($workspace->owner_id === $user->id) {
        return 'owner';
    }

    return $workspace->members()
        ->where('user_id', $user->id)
        ->value('workspace_user.role');
}

public function view(User $user, Project $project): bool
{
    return $this->getRole($user, $project->workspace) !== null;
}
```

---

## PART 7 — 動作確認

### ターミナルを4つ用意する

```bash
# ターミナル1：PHP サーバー
php artisan serve

# ターミナル2：Vite（フロントエンドビルド）
npm run dev

# ターミナル3：キューワーカー（ShouldBroadcast はキュー経由で処理される）
php artisan queue:work

# ターミナル4：Reverb WebSocket サーバー
php artisan reverb:start
```

### Show ページの URL

```
http://localhost:8000/workspaces/{workspace_id}/projects/{project_id}
```

ID を Tinker で確認する：

```bash
php artisan tinker
```

```php
App\Models\Workspace::with('projects')->get()->map(fn($w) => [
    'workspace_id' => $w->id,
    'projects' => $w->projects->pluck('id', 'name'),
])
```

### Tinker でブロードキャストをテストする

```php
$task = App\Models\Task::with('project.workspace')->first();
$user = App\Models\User::first();

// ブロードキャストイベントを手動 dispatch
App\Events\TaskStatusChanged::dispatch($task, $user, 'todo');
```

ブラウザで Show ページを開いた状態で実行すると、通知バナーが 4 秒間表示されれば成功です。

### WebSocket 接続の確認

ブラウザの DevTools > Network > WS タブを開くと：

```
ws://localhost:8080/app/[REVERB_APP_KEY]?...
```

への接続が確立されているのが確認できます。

---

## よくあるエラーと対処法

### WebSocket 接続失敗（`Connection refused`）

**原因：** `php artisan reverb:start` を起動していない  
**対処：** ターミナル4で Reverb を起動する

### チャンネル認証が 403 になる

**原因1：** `routes/channels.php` の認可ロジックが false を返している  
**原因2：** ユーザーが `workspace_user` テーブルに存在せず、`owner_id` チェックも漏れている  
**対処：** `channels.php` に `|| $workspace->owner_id === $user->id` を追加する

### ブラウザに通知が来ない（Reverb は起動している）

**原因：** `queue:work` が動いていない。`ShouldBroadcast` はデフォルトでキュー経由  
**対処：**
```bash
php artisan queue:work
```

### `window.Echo is not defined`

**原因：** `@laravel/echo-vue` を使う場合は `configureEcho` の設定が `app.ts` に必要  
**対処：** `app.ts` に `configureEcho({...})` が正しく記述されているか確認する

### `import.meta.env.VITE_*` が TypeScript でエラーになる

**原因：** `vite-env.d.ts` に型定義が追加されていない  
**対処：** `resources/js/types/vite-env.d.ts` に `ImportMetaEnv` を追記する

---

## ファイル変更サマリー

| ファイル | 変更内容 |
|---------|---------|
| `app/Events/TaskStatusChanged.php` | 新規作成：ブロードキャスト専用イベント |
| `app/Http/Controllers/Api/TaskController.php` | `update()` に `TaskStatusChanged::dispatch()` を追加 |
| `app/Http/Controllers/ProjectController.php` | `show()` を実装、`routes/web.php` に `show` を追加 |
| `app/Policies/ProjectPolicy.php` | `getRole()` に `owner_id` チェックを追加、`view()` を実装 |
| `routes/channels.php` | `workspace.{workspaceId}` チャンネルの認可ルールを定義 |
| `resources/js/app.ts` | `configureEcho` に Reverb 接続設定を追記 |
| `resources/js/types/vite-env.d.ts` | `VITE_REVERB_*` の型定義を追加 |
| `resources/js/types/index.d.ts` | `TaskStatusChangedPayload` 型を追加 |
| `resources/js/Pages/Projects/Show.vue` | 新規作成：`useEcho` でリアルタイムタスク一覧 |
| `.env` | `BROADCAST_CONNECTION` および Reverb 設定を追加 |

---

## 学習ポイントまとめ

- **Reverb** — Laravel 公式 WebSocket サーバー。`php artisan install:broadcasting` で一括セットアップ
- **`ShouldBroadcast`** — このインターフェースを実装するだけでイベントが WebSocket 経由で送信される
- **`PrivateChannel`** — `routes/channels.php` で認可ロジックを定義。未認証・非メンバーは購読不可
- **`broadcastWith()`** — フロントに届けるデータを明示的に絞る（全プロパティを渡すと情報漏洩のリスク）
- **`broadcastAs()`** — フロントで受け取るイベント名。カスタム名は先頭に `.` を付けて `listen`
- **`useEcho` コンポーザブル** — `@laravel/echo-vue` v2 の Vue 統合 API。型安全で購読解除も簡単
- **`stopListening()` + `onUnmounted`** — コンポーネント破棄時に必ず購読解除してメモリリークを防ぐ
- **Policy の `owner_id` チェック** — pivot テーブルに頼らず、`owner_id` を直接比較することで確実な認可を実現
