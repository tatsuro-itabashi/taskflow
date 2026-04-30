# Day 12 — キャッシュ＆パフォーマンス

Cache facade でデータベースへの負荷を減らし、N+1 問題を解消してレスポンスを高速化します。  
仕上げに、ワークスペースの統計情報を表示するダッシュボードを実装します。

---

## 今日のゴール

```
【問題】
  - 同じクエリを何度も実行している（キャッシュ未使用）
  - リレーションの取得でループごとにクエリが走っている（N+1 問題）

【解決後】
  - 重いクエリ結果をファイル / Redis にキャッシュ → DB 負荷を削減
  - eager loading で 1 回のクエリにまとめる → レスポンスが高速化
  - ダッシュボードにワークスペース統計とアクティビティを表示
```

---

## PART 1 — N+1 問題を発見・解消する

### N+1 問題とは

```php
// ❌ N+1 問題：プロジェクトの数だけクエリが走る
$projects = Project::all(); // クエリ 1回

foreach ($projects as $project) {
    echo $project->creator->name; // ← ここでクエリが N 回走る！
}
// 10プロジェクトなら合計 11 回クエリ実行
```

```php
// ✅ 解消後：クエリは 2回だけ
$projects = Project::with('creator')->get();

foreach ($projects as $project) {
    echo $project->creator->name; // メモリ内で解決 → クエリなし
}
```

### よくある N+1 パターンと解消方法

```php
// ❌ リレーション未ロード
$tasks = Task::all();
$tasks->each(fn($t) => $t->assignee->name); // N+1

// ✅ with() で事前ロード
$tasks = Task::with('assignee')->get();

// ❌ ネストしたリレーション
$workspaces = Workspace::all();
$workspaces->each(fn($w) => $w->projects->each(fn($p) => $p->tasks->count()));

// ✅ ドット記法でネストも一括ロード
$workspaces = Workspace::with('projects.tasks')->get();

// ❌ ループ内で count
$projects->each(fn($p) => $p->tasks()->count()); // N+1

// ✅ withCount() で一括取得（tasks_count カラムとして取れる）
$projects = Project::withCount('tasks')->get();
```

### withCount() の応用：条件付きカウント

```php
// スコープ付きカウントで複数の集計を一括取得
$projects = $workspace->projects()->withCount([
    'tasks',
    'tasks as todo_count'        => fn($q) => $q->where('status', 'todo'),
    'tasks as in_progress_count' => fn($q) => $q->where('status', 'in_progress'),
    'tasks as done_count'        => fn($q) => $q->where('status', 'done'),
])->get();

// 結果の使い方
$projects->sum('tasks_count');      // 全タスク数
$projects->sum('todo_count');       // 未着手タスク数
$projects->sum('done_count');       // 完了タスク数
```

### Laravel Debugbar で N+1 を検出する（開発環境のみ）

```bash
composer require barryvdh/laravel-debugbar --dev
```

インストール後、ブラウザ画面の下部にデバッグバーが表示され、実行クエリ数と内容を確認できます。

```dotenv
DEBUGBAR_ENABLED=true  # .env で有効・無効を切り替え（本番は false）
```

### Debugbar なしでクエリを確認する

```php
DB::enableQueryLog();

$projects = App\Models\Project::with('creator')->withCount('tasks')->get();

dump(count(DB::getQueryLog())); // クエリ本数
dump(DB::getQueryLog());        // クエリ内容の詳細
```

---

## PART 2 — Cache facade の基本

### キャッシュドライバーの設定

```dotenv
# 開発環境（storage/framework/cache/ にファイル保存）
CACHE_STORE=file

# 本番環境推奨
CACHE_STORE=redis
```

### 主要なメソッド

```php
use Illuminate\Support\Facades\Cache;

// ① remember：なければ実行してキャッシュ、あればキャッシュを返す（最もよく使う）
$value = Cache::remember('key', 300, function () {
    return DB::table('...')->get(); // 重い処理
});

// ② rememberForever：有効期限なし（手動クリアまで保持）
$value = Cache::rememberForever('key', fn() => /* 重い処理 */);

// ③ put：値をセット（上書き）
Cache::put('key', $value, 3600); // 3600秒 = 1時間

// ④ get：値を取得（なければ null）
$value = Cache::get('key');
$value = Cache::get('key', 'default'); // なければデフォルト値

// ⑤ forget：特定キーを削除
Cache::forget('key');

// ⑥ flush：全キャッシュを削除（開発時のリセット用）
Cache::flush();

// ⑦ has：キーの存在確認
Cache::has('key'); // true / false
```

### キャッシュキーの命名規則

複数ユーザーやワークスペースが使うアプリでは、キーにIDを含めて衝突を防ぎます：

```php
"workspace.{$workspaceId}.stats"         // ワークスペース統計
"user.{$userId}.recent_activity"          // ユーザーのアクティビティ
"project.{$projectId}.tasks"              // プロジェクトのタスク
```

### TTL（有効期限）の設計指針

| データの種類 | TTL の目安 | 理由 |
|-------------|------------|------|
| リアルタイム性が高いアクティビティ | 60秒 | 更新頻度が高い |
| ワークスペース統計 | 5分（300秒） | 多少古くても許容できる |
| マスタデータ・設定 | 1時間以上 | めったに変わらない |

---

## PART 3 — DashboardController の実装

### `app/Http/Controllers/DashboardController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        // ユーザーが所属するワークスペース一覧（N+1 対策済み）
        $workspaces = $user->workspaces()
            ->with('owner')
            ->withCount(['projects', 'members'])
            ->get();

        // ワークスペースごとの統計（5分キャッシュ）
        $stats = $workspaces->map(function (Workspace $workspace) {
            return Cache::remember(
                "workspace.{$workspace->id}.stats",
                300,
                function () use ($workspace) {
                    $projects = $workspace->projects()->withCount([
                        'tasks',
                        'tasks as todo_count'        => fn($q) => $q->where('status', 'todo'),
                        'tasks as in_progress_count' => fn($q) => $q->where('status', 'in_progress'),
                        'tasks as done_count'        => fn($q) => $q->where('status', 'done'),
                    ])->get();

                    return [
                        'workspace_id'      => $workspace->id,
                        'workspace_name'    => $workspace->name,
                        'projects_count'    => $projects->count(),
                        'total_tasks'       => $projects->sum('tasks_count'),
                        'todo_count'        => $projects->sum('todo_count'),
                        'in_progress_count' => $projects->sum('in_progress_count'),
                        'done_count'        => $projects->sum('done_count'),
                    ];
                }
            );
        });

        // 最近のアクティビティ（1分キャッシュ）
        $workspaceIds = $workspaces->pluck('id');
        $recentActivity = Cache::remember(
            "user.{$user->id}.recent_activity",
            60,
            function () use ($workspaceIds) {
                return ActivityLog::with('user')
                    ->whereIn('workspace_id', $workspaceIds)
                    ->latest()
                    ->take(10)
                    ->get()
                    ->map(fn ($log) => [
                        'id'          => $log->id,
                        'description' => $log->description, // accessor（Day 9 で定義）
                        'user_name'   => $log->user->name,
                        'action'      => $log->action,
                        'created_at'  => $log->created_at->diffForHumans(),
                    ]);
            }
        );

        return Inertia::render('Dashboard', [
            'stats'          => $stats,
            'recentActivity' => $recentActivity,
        ]);
    }
}
```

**`__invoke()` メソッドについて：**  
クラスに `__invoke()` を定義すると、ルートでクラス名だけを指定できます（シングルアクションコントローラー）。  
処理が1つしかないコントローラーに適しています。

```php
// routes/web.php
Route::get('/dashboard', DashboardController::class)->name('dashboard');
// ↑ メソッド名を書かなくていい
```

---

## PART 4 — キャッシュの無効化

キャッシュは「古いデータが表示され続ける」問題があります。  
Observer の各ライフサイクルフックでキャッシュを自動クリアします。

### `app/Observers/TaskObserver.php`

```php
use Illuminate\Support\Facades\Cache;

public function created(Task $task): void
{
    Log::info("Task created: [{$task->id}] {$task->title}");
    // タスクが追加されたらワークスペースの統計キャッシュをクリア
    Cache::forget("workspace.{$task->project->workspace_id}.stats");
}

public function updated(Task $task): void
{
    // ステータスが変わったときだけクリア（無駄なクリアを防ぐ）
    if ($task->wasChanged('status')) {
        Cache::forget("workspace.{$task->project->workspace_id}.stats");
    }
}

public function deleted(Task $task): void
{
    Log::info("Task deleted: [{$task->id}] {$task->title}");
    Cache::forget("workspace.{$task->project->workspace_id}.stats");
}
```

### キャッシュ無効化のパターン比較

```php
// パターン1：変更時に即時クリア（今回の実装）
Cache::forget("workspace.{$workspaceId}.stats");
// → 次のリクエストで DB から再取得。確実だが再取得コストが発生

// パターン2：短い TTL で自然に期限切れを待つ
Cache::remember('key', 60, fn() => /* ... */);
// → 最大 60 秒古いデータが出る可能性あり。実装がシンプル

// パターン3：tags でグループをまとめてクリア（Redis が必要）
Cache::tags(["workspace.{$workspaceId}"])->put('stats', $data, 300);
Cache::tags(["workspace.{$workspaceId}"])->flush(); // タグ内を全削除
// → 関連するキャッシュをまとめて管理できる。Redis 環境限定
```

---

## PART 5 — Dashboard Vue ページ

### `resources/js/Pages/Dashboard.vue`

```vue
<template>
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">ダッシュボード</h2>
        </template>

        <div class="py-8 px-6 space-y-8">

            <!-- ワークスペース統計カード -->
            <section>
                <h3 class="text-lg font-medium mb-4">ワークスペース概要</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div
                        v-for="ws in stats"
                        :key="ws.workspace_id"
                        class="bg-white rounded-lg shadow p-5 border border-gray-100"
                    >
                        <h4 class="font-semibold text-gray-700 mb-3">{{ ws.workspace_name }}</h4>

                        <div class="grid grid-cols-2 gap-3 text-center">
                            <div class="bg-gray-50 rounded p-2">
                                <p class="text-2xl font-bold text-gray-800">{{ ws.projects_count }}</p>
                                <p class="text-xs text-gray-500">プロジェクト</p>
                            </div>
                            <div class="bg-gray-50 rounded p-2">
                                <p class="text-2xl font-bold text-gray-800">{{ ws.total_tasks }}</p>
                                <p class="text-xs text-gray-500">総タスク</p>
                            </div>
                        </div>

                        <!-- タスク進捗バー -->
                        <div class="mt-4">
                            <div class="flex justify-between text-xs text-gray-500 mb-1">
                                <span>進捗</span>
                                <span>{{ ws.done_count }} / {{ ws.total_tasks }} 完了</span>
                            </div>
                            <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-green-500 rounded-full transition-all"
                                    :style="{ width: progressPercent(ws) + '%' }"
                                />
                            </div>
                        </div>

                        <!-- ステータス内訳バッジ -->
                        <div class="mt-3 flex gap-2 text-xs">
                            <span class="px-2 py-1 bg-gray-100 text-gray-600 rounded">
                                未着手 {{ ws.todo_count }}
                            </span>
                            <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded">
                                進行中 {{ ws.in_progress_count }}
                            </span>
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded">
                                完了 {{ ws.done_count }}
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 最近のアクティビティ -->
            <section>
                <h3 class="text-lg font-medium mb-4">最近のアクティビティ</h3>
                <div class="bg-white rounded-lg shadow border border-gray-100 divide-y">
                    <div
                        v-for="log in recentActivity"
                        :key="log.id"
                        class="px-5 py-3 flex items-center gap-3"
                    >
                        <span class="text-lg">{{ actionIcon(log.action) }}</span>
                        <div class="flex-1">
                            <p class="text-sm text-gray-800">
                                <span class="font-medium">{{ log.user_name }}</span>
                                が{{ log.description }}
                            </p>
                        </div>
                        <span class="text-xs text-gray-400 shrink-0">{{ log.created_at }}</span>
                    </div>

                    <div v-if="recentActivity.length === 0"
                         class="px-5 py-8 text-center text-sm text-gray-400">
                        アクティビティはありません
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

interface WorkspaceStat {
    workspace_id: number;
    workspace_name: string;
    projects_count: number;
    total_tasks: number;
    todo_count: number;
    in_progress_count: number;
    done_count: number;
}

interface ActivityLog {
    id: number;
    description: string;
    user_name: string;
    action: string;
    created_at: string;
}

defineProps<{
    stats: WorkspaceStat[];
    recentActivity: ActivityLog[];
}>();

function progressPercent(ws: WorkspaceStat): number {
    if (ws.total_tasks === 0) return 0;
    return Math.round((ws.done_count / ws.total_tasks) * 100);
}

function actionIcon(action: string): string {
    const icons: Record<string, string> = {
        created: '✅',
        updated: '✏️',
        deleted: '🗑️',
    };
    return icons[action] ?? '📌';
}
</script>
```

---

## PART 6 — 動作確認

### Tinker でキャッシュの動作を確認する

```bash
php artisan tinker
```

```php
use Illuminate\Support\Facades\Cache;

// キャッシュに保存
Cache::put('test.key', 'hello', 60);
Cache::get('test.key');  // → "hello"

// remember：初回は DB 問い合わせ、2回目はキャッシュから
Cache::remember('workspace.1.stats', 300, function () {
    echo "DB に問い合わせ中...\n";
    return App\Models\Workspace::find(1)?->projects()->count();
});
// 初回 → "DB に問い合わせ中..." が表示される
// 2回目 → 何も表示されずに即座に返る

// 手動クリア
Cache::forget('workspace.1.stats');

// 全クリア（開発中のリセット）
Cache::flush();
```

### アートサンコマンドでキャッシュをクリアする

```bash
php artisan cache:clear    # キャッシュのみ
php artisan config:clear   # 設定キャッシュ
php artisan route:clear    # ルートキャッシュ
php artisan view:clear     # ビューキャッシュ
php artisan optimize:clear # 上記すべて一括クリア
```

### ダッシュボードを確認する

```
http://localhost:8000/dashboard
```

ワークスペース統計カードと最近のアクティビティが表示されれば成功です。

---

## よくあるエラーと対処法

### `Cache::tags()` が使えない

**原因：** `file` ドライバーはタグ機能非対応  
**対処：** Redis ドライバーに切り替えるか、`Cache::forget()` で個別クリアする

### ダッシュボードの stats が空になる

**原因：** ユーザーが `workspace_user` テーブルに登録されておらず、`workspaces()` が空を返している  
**対処：** `owner_id` も含めてワークスペースを取得する（Day 10 での修正内容を参照）

### キャッシュが古いデータを返し続ける

**原因：** Observer で `Cache::forget()` が呼ばれていない / Observer が登録されていない  
**対処：** `php artisan cache:clear` で手動クリアし、`AppServiceProvider` の `Task::observe()` を確認する

---

## ファイル変更サマリー

| ファイル | 変更内容 |
|---------|---------|
| `app/Http/Controllers/DashboardController.php` | 新規作成：統計＋アクティビティをキャッシュ付きで取得 |
| `app/Observers/TaskObserver.php` | `created` / `updated` / `deleted` にキャッシュクリアを追加 |
| `app/Http/Controllers/ProjectController.php` | `index()` の `withCount('tasks')` コメントアウトを解除 |
| `routes/web.php` | `/dashboard` を `DashboardController` に差し替え |
| `resources/js/Pages/Dashboard.vue` | 統計カード＋進捗バー＋アクティビティ一覧を表示 |

---

## 学習ポイントまとめ

- **N+1 問題** — ループ内でリレーションを参照するたびにクエリが走る問題。`with()` / `withCount()` で事前ロード（eager loading）して解消する
- **`withCount()` の応用** — `tasks as done_count` のようにエイリアスとスコープを組み合わせると、複数の集計を1クエリで取得できる
- **`Cache::remember()`** — 「なければ DB から取得してキャッシュ、あればキャッシュを返す」をワンライナーで書ける最頻出メソッド
- **TTL の設計** — データの更新頻度・鮮度要件に応じて TTL を変える。アクティビティ（60秒）・統計（5分）・マスタ（1時間以上）
- **Observer でのキャッシュ無効化** — `wasChanged('status')` で変更検知し、不要なキャッシュクリアを防ぐ
- **シングルアクションコントローラー** — `__invoke()` を定義するとルートにクラス名だけ指定できる。処理が1つのコントローラーに最適
- **Debugbar** — 開発環境でクエリ数を視覚的に確認できる。N+1 の発見に不可欠なツール
