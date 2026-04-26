# Day 7 — Pest テスト入門

Laravel 12 + Pest でテストを書く手順です。Feature テスト（HTTP レベル）と Unit テスト（クラス単体）の両方を実装し、認証・認可・API・モデルのアクセサを網羅的に検証します。

---

## 前提条件

- Day 6 までの実装が完了している
- `laravel/breeze` 導入時に `--pest` オプションを付けていた（Pest はインストール済み）

---

## 完成イメージ

```
Tests:    47 passed (114 assertions)
Duration: 2.13s
```

| テストファイル | 種別 | 件数 | 検証内容 |
|-------------|------|------|---------|
| `tests/Feature/Auth/*.php` | Feature | 25件 | Breeze 自動生成の認証テスト |
| `tests/Feature/ProjectTest.php` | Feature | 8件 | プロジェクト CRUD・認可 |
| `tests/Feature/Api/TaskApiTest.php` | Feature | 7件 | REST API・Sanctum トークン |
| `tests/Unit/AttachmentTest.php` | Unit | 5件 | モデルメソッド単体 |

---

## PART 1 — テスト環境の確認

### phpunit.xml の重要設定

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

テスト時は **SQLite のインメモリ DB** を使います。これにより：
- テストが本番 DB に一切影響しない
- ディスク I/O がないため高速
- `RefreshDatabase` で各テスト後に自動リセットされる

---

## PART 2 — Pest の基本構文

```php
// 基本形
test('テスト名', function () {
    // Arrange（準備）
    $user = User::factory()->create();

    // Act（実行）
    $response = $this->actingAs($user)->get('/dashboard');

    // Assert（検証）
    $response->assertStatus(200);
});

// it() 形式（より英文として読みやすい）
it('redirects guests to login', function () {
    get('/dashboard')->assertRedirect('/login');
});

// 共通セットアップ
beforeEach(function () {
    // 各テストの前に実行される
});
```

### よく使うアサーション一覧

```php
// HTTP ステータス
$response->assertStatus(200);
$response->assertStatus(403);
$response->assertStatus(422);

// リダイレクト
$response->assertRedirect('/login');

// Inertia ページ（Vue コンポーネント + props の検証）
$response->assertInertia(fn($page) => $page
    ->component('Projects/Index')
    ->has('projects', 3)
);

// JSON レスポンス
$response->assertJson(['message' => 'OK']);
$response->assertJsonPath('data.title', 'タスク名');
$response->assertJsonCount(3, 'data');
$response->assertJsonStructure(['token']);
$response->assertJsonValidationErrors(['title']);

// DB の状態
$this->assertDatabaseHas('projects', ['name' => 'テスト']);
$this->assertDatabaseMissing('tasks', ['id' => 1]);
$this->assertDatabaseCount('workspaces', 3);

// 認証状態
$this->assertAuthenticated();
$this->assertGuest();

// Pest 流の expect 構文
expect($attachment->isImage())->toBeTrue();
expect($attachment->human_size)->toBe('2 KB');
expect($tasks)->toHaveCount(3);
```

---

## PART 3 — ProjectTest（Feature テスト）

```bash
php artisan make:test ProjectTest
```

`tests/Feature/ProjectTest.php`：

```php
<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\withoutVite;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    withoutVite(); // テスト時は Vite のアセットビルドをスキップ
});

/**
 * テスト用ヘルパー：owner ユーザー + ワークスペースを生成して返す
 * @return array{User, Workspace}
 */
function setupWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, ['role' => 'owner']);

    return [$owner, $workspace];
}

// プロジェクト一覧
test('owner can view project list', function () {
    [$owner, $workspace] = setupWorkspace();

    Project::factory(3)->create([
        'workspace_id' => $workspace->id,
        'created_by'   => $owner->id,
    ]);

    actingAs($owner)
        ->get(route('workspaces.projects.index', $workspace))
        ->assertStatus(200)
        ->assertInertia(fn($page) => $page
            ->component('Projects/Index')
            ->has('projects', 3)
        );
});

test('guest cannot view project list', function () {
    [, $workspace] = setupWorkspace();

    get(route('workspaces.projects.index', $workspace))
        ->assertRedirect('/login');
});

test('non-member cannot view project list', function () {
    [, $workspace] = setupWorkspace();
    $other = User::factory()->create();

    actingAs($other)
        ->get(route('workspaces.projects.index', $workspace))
        ->assertStatus(403);
});

// プロジェクト作成
test('owner can create a project', function () {
    [$owner, $workspace] = setupWorkspace();

    actingAs($owner)
        ->post(route('workspaces.projects.store', $workspace), [
            'name'  => 'テストプロジェクト',
            'color' => '#6366f1',
        ])
        ->assertRedirect();

    assertDatabaseHas('projects', [
        'name'         => 'テストプロジェクト',
        'workspace_id' => $workspace->id,
        'created_by'   => $owner->id,
    ]);
});

test('member cannot create a project', function () {
    [$owner, $workspace] = setupWorkspace();
    $member = User::factory()->create();
    $workspace->members()->attach($member->id, ['role' => 'member']);

    actingAs($member)
        ->post(route('workspaces.projects.store', $workspace), [
            'name' => 'メンバーが作成',
        ])
        ->assertStatus(403);

    assertDatabaseMissing('projects', ['name' => 'メンバーが作成']);
});

test('project name is required', function () {
    [$owner, $workspace] = setupWorkspace();

    actingAs($owner)
        ->post(route('workspaces.projects.store', $workspace), ['name' => ''])
        ->assertSessionHasErrors(['name']);
});

test('project name cannot exceed 255 characters', function () {
    [$owner, $workspace] = setupWorkspace();

    actingAs($owner)
        ->post(route('workspaces.projects.store', $workspace), [
            'name' => str_repeat('a', 256),
        ])
        ->assertSessionHasErrors(['name']);
});

// プロジェクト削除
test('owner can delete a project', function () {
    [$owner, $workspace] = setupWorkspace();
    $project = Project::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by'   => $owner->id,
    ]);

    actingAs($owner)
        ->delete(route('workspaces.projects.destroy', [$workspace, $project]))
        ->assertRedirect();

    assertDatabaseMissing('projects', ['id' => $project->id]);
});

test('member cannot delete a project', function () {
    [$owner, $workspace] = setupWorkspace();
    $member = User::factory()->create();
    $workspace->members()->attach($member->id, ['role' => 'member']);
    $project = Project::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by'   => $owner->id,
    ]);

    actingAs($member)
        ->delete(route('workspaces.projects.destroy', [$workspace, $project]))
        ->assertStatus(403);

    assertDatabaseHas('projects', ['id' => $project->id]);
});
```

---

## PART 4 — TaskApiTest（API の Feature テスト）

```bash
php artisan make:test Api/TaskApiTest
```

`tests/Feature/Api/TaskApiTest.php`：

```php
<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\withToken;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @return array{User, Project, string}
 */
function setupApi(): array
{
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    $project = Project::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by'   => $user->id,
    ]);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $project, $token];
}

// トークン発行
test('user can get api token', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    postJson('/api/tokens/create', [
        'email'    => $user->email,
        'password' => 'password',
    ])
    ->assertStatus(200)
    ->assertJsonStructure(['token']);
});

test('cannot get token with wrong password', function () {
    $user = User::factory()->create();

    postJson('/api/tokens/create', [
        'email'    => $user->email,
        'password' => 'wrong',
    ])
    ->assertStatus(422);
});

// タスク一覧
test('authenticated user can get task list', function () {
    [$user, $project, $token] = setupApi();

    Task::factory(3)->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
    ]);

    withToken($token)
        ->getJson("/api/v1/projects/{$project->id}/tasks")
        ->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

test('unauthenticated user cannot access api', function () {
    [, $project] = setupApi();

    getJson("/api/v1/projects/{$project->id}/tasks")
        ->assertStatus(401);
});

// タスク作成
test('user can create a task via api', function () {
    [$user, $project, $token] = setupApi();

    withToken($token)
        ->postJson("/api/v1/projects/{$project->id}/tasks", [
            'title'    => 'API テストタスク',
            'priority' => 'high',
            'status'   => 'todo',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.title', 'API テストタスク')
        ->assertJsonPath('data.priority', 'high');

    assertDatabaseHas('tasks', ['title' => 'API テストタスク']);
});

test('task title is required', function () {
    [$user, $project, $token] = setupApi();

    withToken($token)
        ->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title']);
});

// タスク更新（PATCH）
test('user can update task status', function () {
    [$user, $project, $token] = setupApi();
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'status'     => 'todo',
    ]);

    withToken($token)
        ->patchJson("/api/v1/projects/{$project->id}/tasks/{$task->id}", [
            'status' => 'in_progress',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'in_progress');

    assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'in_progress']);
});

// タスク削除
test('user can delete a task', function () {
    [$user, $project, $token] = setupApi();
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
    ]);

    withToken($token)
        ->deleteJson("/api/v1/projects/{$project->id}/tasks/{$task->id}")
        ->assertStatus(200)
        ->assertJsonPath('message', 'タスクを削除しました');

    assertDatabaseMissing('tasks', ['id' => $task->id]);
});
```

---

## PART 5 — AttachmentTest（Unit テスト）

```bash
php artisan make:test AttachmentTest --unit
```

`tests/Unit/AttachmentTest.php`：

```php
<?php

use App\Models\Attachment;

test('isImage returns true for image mime types', function () {
    $attachment = new Attachment(['mime_type' => 'image/jpeg']);
    expect($attachment->isImage())->toBeTrue();

    $attachment->mime_type = 'image/png';
    expect($attachment->isImage())->toBeTrue();
});

test('isImage returns false for non-image mime types', function () {
    $attachment = new Attachment(['mime_type' => 'application/pdf']);
    expect($attachment->isImage())->toBeFalse();

    $attachment->mime_type = 'application/zip';
    expect($attachment->isImage())->toBeFalse();
});

test('human size returns bytes for small files', function () {
    $attachment = new Attachment(['size' => 512]);
    expect($attachment->human_size)->toBe('512 B');
});

test('human size returns KB for medium files', function () {
    $attachment = new Attachment(['size' => 2048]);
    expect($attachment->human_size)->toBe('2 KB');
});

test('human size returns MB for large files', function () {
    $attachment = new Attachment(['size' => 1024 * 1024 * 3]);
    expect($attachment->human_size)->toBe('3 MB');
});
```

---

## PART 6 — Factory の整備

### TaskFactory

`database/factories/TaskFactory.php`：

```php
<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id'  => Project::factory(),
            'created_by'  => User::factory(),
            'assignee_id' => null,
            'title'       => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'status'      => $this->faker->randomElement(['todo', 'in_progress', 'in_review', 'done']),
            'priority'    => $this->faker->randomElement(['low', 'medium', 'high', 'urgent']),
            'due_date'    => $this->faker->optional()->dateTimeBetween('now', '+30 days'),
            'position'    => $this->faker->numberBetween(1, 100),
        ];
    }
}
```

### ProjectFactory

`database/factories/ProjectFactory.php`：

```php
public function definition(): array
{
    return [
        'workspace_id' => Workspace::factory(),
        'created_by'   => User::factory(),
        'name'         => $this->faker->words(3, true),
        'description'  => $this->faker->sentence(),
        'color'        => $this->faker->hexColor(),
        'status'       => 'active',
    ];
}
```

---

## PART 7 — テストの実行

```bash
# 全テストを実行
php artisan test

# 特定ファイルだけ実行
php artisan test tests/Feature/ProjectTest.php
php artisan test tests/Feature/Api/TaskApiTest.php
php artisan test tests/Unit/AttachmentTest.php

# テスト名で絞り込み
php artisan test --filter="owner can create"

# カバレッジレポート（Xdebug が必要）
php artisan test --coverage
```

---

## ファイル変更サマリー

| ファイル | 変更内容 |
|---------|---------|
| `tests/Feature/ProjectTest.php` | 新規作成：プロジェクト CRUD・認可テスト 8件 |
| `tests/Feature/Api/TaskApiTest.php` | 新規作成：REST API・Sanctum トークンテスト 7件 |
| `tests/Unit/AttachmentTest.php` | 新規作成：モデルメソッド Unit テスト 5件 |
| `database/factories/TaskFactory.php` | 新規作成：タスクのダミーデータ生成 |
| `database/factories/ProjectFactory.php` | `created_by` を追加 |

---

## よくあるエラーと対処法

### `withoutVite()` を付け忘れると Inertia テストが失敗する

**原因：** テスト時に Vite のマニフェストファイルが存在しないためエラーになる。

**対処：** Inertia ページを検証するテストファイルの `beforeEach` に追加する。

```php
beforeEach(function () {
    withoutVite();
});
```

### Factory で関連モデルが見つからないエラー

**原因：** Factory 内で `Project::factory()` のようにネストしているが、`RefreshDatabase` でリセットされて存在しない。

**対処：** テスト内で明示的に親モデルを先に作成し、ID を指定する。

```php
// NG（Factory 任せ）
Task::factory()->create();

// OK（親を明示的に作成して紐付け）
$project = Project::factory()->create(['workspace_id' => $workspace->id]);
Task::factory()->create(['project_id' => $project->id]);
```

---

## 学習ポイントまとめ

- **`RefreshDatabase`** — 各テスト後に DB をリセット。本番 DB に影響しない SQLite インメモリを使用
- **`actingAs($user)`** — 指定ユーザーで認証した状態でリクエストを送る
- **`withToken($token)`** — Sanctum の Bearer トークンを Authorization ヘッダーに付けて送る
- **`withoutVite()`** — Inertia のテストで必須。Vite マニフェストなしでテストを動かす
- **`assertInertia`** — Vue コンポーネント名と props の中身を検証できる
- **`assertJsonPath('data.title', 'xxx')`** — ネストした JSON の特定パスを検証
- **`assertSessionHasErrors(['name'])`** — FormRequest のバリデーションエラーを検証
- **`setupWorkspace()` / `setupApi()`** — テスト用ヘルパー関数で前提データをまとめる
- **テスト名の命名規則** — 「誰が・何をすると・どうなるか」で書く（`owner can create a project`）
