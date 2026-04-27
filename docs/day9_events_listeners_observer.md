# Day 9 — Events・Listeners・Observer

Laravel 12 のイベント駆動設計を学ぶ手順です。タスクの作成・更新・削除を Event として発火し、Listener がアクティビティログを自動記録します。あわせて Observer でモデルのライフサイクルを監視する実装も行います。

---

## 前提条件

- Day 8 までの実装が完了している
- `tasks` / `projects` / `workspaces` テーブルが存在する

---

## なぜイベント駆動設計が必要か

```
従来の書き方（コントローラに全部書く）
  TaskController::store()
    → タスク保存
    → ログ記録         ← コントローラが肥大化
    → 通知送信         ← 関心事が混在
    → ポイント付与     ← テストしにくい

イベント駆動の書き方
  TaskController::store()
    → タスク保存
    → TaskCreated イベントを発火  ← ここだけ書く

  TaskCreated を「聴いている」Listener が自動で動く
    → LogTaskActivity  → ログ記録
    → NotifyAssignee   → 通知送信（Day 11 で実装）
```

**メリット：**
- コントローラは「何が起きたか」を通知するだけ
- 機能追加は「新しい Listener を追加するだけ」でコントローラを触らない
- 各 Listener が独立しているためテストしやすい

---

## 完成イメージ

```
タスク作成
  TaskController::store()
    → Task 保存
    → TaskCreated::dispatch()
         └→ LogTaskActivity::handleTaskCreated()
               → activity_logs にレコード作成

タスク更新
  TaskController::update()
    → Task 更新
    → TaskUpdated::dispatch($changes)
         └→ LogTaskActivity::handleTaskUpdated()
               → 変更フィールドのみ記録

タスク削除
  TaskController::destroy()
    → Task 削除
    → TaskDeleted::dispatch()
         └→ LogTaskActivity::handleTaskDeleted()
               → 削除したタスク名を記録
```

---

## PART 1 — ActivityLog テーブルを作成する

```bash
php artisan make:model ActivityLog -m
```

### マイグレーション

`database/migrations/xxxx_create_activity_logs_table.php`：

```php
public function up(): void
{
    Schema::create('activity_logs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->string('subject_type');          // 'task', 'project' など操作対象の種別
        $table->unsignedBigInteger('subject_id'); // 操作対象の ID
        $table->string('action');                 // 'created', 'updated', 'deleted'
        $table->json('changes')->nullable();       // 変更前後の値
        $table->timestamps();

        // 検索パフォーマンス向上のためインデックスを追加
        $table->index(['subject_type', 'subject_id']);
        $table->index('workspace_id');
    });
}
```

```bash
php artisan migrate
```

### ActivityLog モデル

`app/Models/ActivityLog.php`：

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'subject_type',
        'subject_id',
        'action',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array', // JSON カラムを自動で配列に変換
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ログの説明文を生成するアクセサ */
    public function getDescriptionAttribute(): string
    {
        $subject = $this->subject_type;
        $actions = [
            'created' => "新しい {$subject} を作成しました",
            'updated' => "{$subject} を更新しました",
            'deleted' => "{$subject} を削除しました",
        ];

        return $actions[$this->action] ?? "{$subject} を操作しました";
    }
}
```

---

## PART 2 — Event クラスを作成する

Event は「何かが起きた」という事実を表すクラスです。データを持ち運ぶ入れ物です。

```bash
php artisan make:event TaskCreated
php artisan make:event TaskUpdated
php artisan make:event TaskDeleted
```

### TaskCreated

`app/Events/TaskCreated.php`：

```php
<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly User $createdBy,
    ) {}
}
```

### TaskUpdated

`app/Events/TaskUpdated.php`：

```php
<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly User $updatedBy,
        public readonly array $changes, // ['old' => [...], 'new' => [...]]
    ) {}
}
```

### TaskDeleted

`app/Events/TaskDeleted.php`：

```php
<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskDeleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Task $task,    // 削除前の情報を保持するため clone して渡す
        public readonly User $deletedBy,
    ) {}
}
```

---

## PART 3 — Listener クラスを実装する

Listener は Event を受け取って実際の処理を行うクラスです。

```bash
php artisan make:listener LogTaskActivity --event=TaskCreated
```

`app/Listeners/LogTaskActivity.php`：

```php
<?php

namespace App\Listeners;

use App\Events\TaskCreated;
use App\Events\TaskDeleted;
use App\Events\TaskUpdated;
use App\Models\ActivityLog;

class LogTaskActivity
{
    /** TaskCreated イベントを処理する */
    public function handleTaskCreated(TaskCreated $event): void
    {
        ActivityLog::create([
            'workspace_id' => $event->task->project->workspace_id,
            'user_id'      => $event->createdBy->id,
            'subject_type' => 'task',
            'subject_id'   => $event->task->id,
            'action'       => 'created',
            'changes'      => null,
        ]);
    }

    /** TaskUpdated イベントを処理する */
    public function handleTaskUpdated(TaskUpdated $event): void
    {
        // 実際に変更がある場合のみ記録する
        if (empty($event->changes['old']) && empty($event->changes['new'])) {
            return;
        }

        ActivityLog::create([
            'workspace_id' => $event->task->project->workspace_id,
            'user_id'      => $event->updatedBy->id,
            'subject_type' => 'task',
            'subject_id'   => $event->task->id,
            'action'       => 'updated',
            'changes'      => $event->changes,
        ]);
    }

    /** TaskDeleted イベントを処理する */
    public function handleTaskDeleted(TaskDeleted $event): void
    {
        ActivityLog::create([
            'workspace_id' => $event->task->project->workspace_id,
            'user_id'      => $event->deletedBy->id,
            'subject_type' => 'task',
            'subject_id'   => $event->task->id,
            'action'       => 'deleted',
            'changes'      => ['title' => $event->task->title], // タイトルだけ残す
        ]);
    }
}
```

---

## PART 4 — Observer を実装する

Observer はモデルの**ライフサイクル全体**を1ファイルで監視するクラスです。

```bash
php artisan make:observer TaskObserver --model=Task
```

`app/Observers/TaskObserver.php`：

```php
<?php

namespace App\Observers;

use App\Models\Task;
use Illuminate\Support\Facades\Log;

class TaskObserver
{
    /** タスクが作成される直前（DB 保存前の自動加工） */
    public function creating(Task $task): void
    {
        // position が未設定なら自動で末尾に配置
        if (! $task->position) {
            $task->position = Task::where('project_id', $task->project_id)
                ->max('position') + 1;
        }
    }

    /** タスクが作成された直後 */
    public function created(Task $task): void
    {
        Log::info("Task created: [{$task->id}] {$task->title}");
    }

    /** タスクが更新された直後 */
    public function updated(Task $task): void
    {
        // status が done になったタイミングなどの処理をここに書ける
    }

    /** タスクが削除された直後（関連ファイルのクリーンアップなど） */
    public function deleted(Task $task): void
    {
        Log::info("Task deleted: [{$task->id}] {$task->title}");
    }
}
```

### Observer のライフサイクルメソッド

| メソッド | タイミング |
|---------|-----------|
| `creating` | DB 保存の直前（`$task` を書き換え可能） |
| `created` | DB 保存の直後 |
| `updating` | UPDATE の直前 |
| `updated` | UPDATE の直後 |
| `saving` | 保存（INSERT/UPDATE）の直前 |
| `saved` | 保存の直後 |
| `deleting` | DELETE の直前 |
| `deleted` | DELETE の直後 |

---

## PART 5 — AppServiceProvider に登録する

`app/Providers/AppServiceProvider.php` の `boot()` に追記：

```php
use App\Events\TaskCreated;
use App\Events\TaskDeleted;
use App\Events\TaskUpdated;
use App\Listeners\LogTaskActivity;
use App\Models\Task;
use App\Observers\TaskObserver;
use Illuminate\Support\Facades\Event;

public function boot(): void
{
    // 既存の設定（Vite, RateLimiter）...

    // Event → Listener の紐付け
    $listener = LogTaskActivity::class;
    Event::listen(TaskCreated::class, [$listener, 'handleTaskCreated']);
    Event::listen(TaskUpdated::class, [$listener, 'handleTaskUpdated']);
    Event::listen(TaskDeleted::class, [$listener, 'handleTaskDeleted']);

    // Observer の登録
    Task::observe(TaskObserver::class);
}
```

---

## PART 6 — Controller でイベントを発火する

`app/Http/Controllers/Api/TaskController.php` を更新：

```php
use App\Events\TaskCreated;
use App\Events\TaskDeleted;
use App\Events\TaskUpdated;

/** タスク作成 */
public function store(Request $request, Project $project): JsonResponse
{
    $validated = $request->validate([/* ... */]);

    $task = $project->tasks()->create([
        ...$validated,
        'created_by' => $request->user()->id,
        'position'   => $project->tasks()->max('position') + 1,
    ]);

    // イベントを発火 → Listener が自動でログを記録する
    TaskCreated::dispatch($task, $request->user());

    return (new TaskResource($task->load('assignee')))
        ->response()
        ->setStatusCode(201);
}

/** タスク更新 */
public function update(Request $request, Project $project, Task $task): TaskResource
{
    $validated = $request->validate([/* ... */]);

    // 変更前の値を先に取得
    $oldValues = $task->only(array_keys($validated));
    $task->update($validated);
    $newValues = $task->fresh()->only(array_keys($validated));

    // 実際に変わったフィールドだけ抽出
    $changes = [];
    foreach ($newValues as $key => $new) {
        if ($oldValues[$key] != $new) {
            $changes['old'][$key] = $oldValues[$key];
            $changes['new'][$key] = $new;
        }
    }

    TaskUpdated::dispatch($task->fresh(), $request->user(), $changes);

    return new TaskResource($task->fresh()->load('assignee'));
}

/** タスク削除 */
public function destroy(Project $project, Task $task): JsonResponse
{
    $deletedTask = clone $task; // 削除前に情報を保持（削除後はアクセス不可になる）
    $task->delete();

    TaskDeleted::dispatch($deletedTask, auth()->user());

    return response()->json(['message' => 'タスクを削除しました']);
}
```

**`clone $task` が必要な理由**  
`$task->delete()` を呼んだ後、`$task` は DB から消えており、`project` リレーションも取得できなくなります。削除前に `clone` で情報を保持することでイベントに渡せます。

---

## PART 7 — Tinker で動作確認する

```bash
php artisan tinker
```

```php
// テスト用データを準備
$task = App\Models\Task::with('project.workspace')->first()
$user = App\Models\User::first()

// TaskCreated イベントを発火
App\Events\TaskCreated::dispatch($task, $user)

// ActivityLog に記録されたか確認
App\Models\ActivityLog::latest()->first()
// → { action: "created", subject_type: "task", subject_id: 1, ... }

// TaskUpdated イベントを発火（変更内容を渡す）
App\Events\TaskUpdated::dispatch($task, $user, [
    'old' => ['status' => 'todo'],
    'new' => ['status' => 'in_progress'],
])

// changes カラムを確認
App\Models\ActivityLog::latest()->first()->changes
// → ['old' => ['status' => 'todo'], 'new' => ['status' => 'in_progress']]

// ワークスペース全体のアクティビティを一覧表示
App\Models\ActivityLog::where('workspace_id', 1)
    ->with('user')
    ->latest()
    ->get()
    ->map(fn($log) => [
        'user'    => $log->user?->name,
        'action'  => $log->action,
        'subject' => $log->subject_type . ' #' . $log->subject_id,
        'desc'    => $log->description,
    ])
```

---

## Event vs Observer の使い分け

```
Observer が向いているケース
  ✅ モデルのライフサイクル全体を1箇所で管理したい
  ✅ DB 保存前のデータ自動加工（position 自動付与など）
  ✅ シンプルなログ・キャッシュクリア

Event / Listener が向いているケース
  ✅ 1つのアクションに複数の処理を紐付けたい
  ✅ 機能を疎結合に保ち、追加・削除しやすくしたい
  ✅ 非同期処理（Listener に ShouldQueue を実装）
  ✅ 例：招待送信 → ログ・通知・ポイント付与を別々の Listener で
```

| | Observer | Event + Listener |
|-|---------|-----------------|
| **発火タイミング** | モデル操作時に自動 | `dispatch()` を明示的に呼ぶ |
| **複数処理の追加** | ❌ 1ファイルに書く | ✅ Listener を追加するだけ |
| **非同期対応** | ❌ | ✅（`ShouldQueue` で可能） |
| **DB 保存前の加工** | ✅（`creating` で可能） | ❌ |
| **向いている処理** | モデルの自動加工・クリーンアップ | ビジネスロジックの通知・連携 |

---

## ファイル変更サマリー

| ファイル | 変更内容 |
|---------|---------|
| `database/migrations/xxxx_create_activity_logs_table.php` | 新規作成：アクティビティログテーブル |
| `app/Models/ActivityLog.php` | 新規作成：description アクセサ、changes の JSON キャスト |
| `app/Events/TaskCreated.php` | 新規作成：タスク作成イベント |
| `app/Events/TaskUpdated.php` | 新規作成：タスク更新イベント（changes 配列を保持） |
| `app/Events/TaskDeleted.php` | 新規作成：タスク削除イベント |
| `app/Listeners/LogTaskActivity.php` | 新規作成：3イベントのログ記録処理 |
| `app/Observers/TaskObserver.php` | 新規作成：position 自動付与・ライフサイクルログ |
| `app/Providers/AppServiceProvider.php` | Event/Listener の紐付け・Observer の登録を追加 |
| `app/Http/Controllers/Api/TaskController.php` | store / update / destroy にイベント発火を追加 |

---

## よくあるエラーと対処法

### Listener が呼ばれない

**原因：** `AppServiceProvider` への `Event::listen()` 登録が漏れている。

**確認：**
```bash
php artisan event:list  # 登録済みイベントの一覧を確認
```

### `$event->task->project->workspace_id` で null エラー

**原因：** `project` リレーションがロードされていない状態でアクセスしている。

**対処：** イベントを発火する前にリレーションをロードする。

```php
// NG
TaskCreated::dispatch($task, $user);

// OK（project を事前にロード）
$task->load('project');
TaskCreated::dispatch($task, $user);
```

### `clone $task` を忘れて削除後に情報が取れない

**原因：** `$task->delete()` 後は DB からレコードが消え、リレーションも取得できない。

**対処：** 削除前に必ず `clone` する。

```php
$deletedTask = clone $task; // ← 削除前にコピー
$task->delete();
TaskDeleted::dispatch($deletedTask, auth()->user()); // コピーを渡す
```

---

## 学習ポイントまとめ

- **Event** — 「何かが起きた」という事実を表すデータクラス。`SerializesModels` でモデルを安全に保持
- **Listener** — Event を受け取って処理するクラス。`ShouldQueue` で非同期にもできる
- **Observer** — モデルのライフサイクル（creating / created / updated / deleted）を1ファイルで監視
- **`Event::dispatch()`** — イベントを発火。登録済みの全 Listener が自動で動く
- **`$task->only([...])`** — 指定キーだけの配列を取得。変更前後の比較に便利
- **`clone $task`** — 削除前にモデルをコピー。削除後もデータにアクセスできるようにする
- **疎結合設計** — コントローラは「dispatch するだけ」。処理の追加は「Listener を足すだけ」
