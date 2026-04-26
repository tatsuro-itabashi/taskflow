<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// テスト用セットアップ：ユーザー・ワークスペース・プロジェクト・APIトークン
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

// ─────────────────────────────────────────
// トークン発行
// ─────────────────────────────────────────
test('user can get api token', function () {
    /** @var User $user */
    $user = User::factory()->create(['password' => bcrypt('password')]);

    postJson('/api/tokens/create', [
        'email'    => $user->email,
        'password' => 'password',
    ])
    ->assertStatus(200)
    ->assertJsonStructure(['token']); // token キーが存在するか
});

test('cannot get token with wrong password', function () {
    /** @var User $user */
    $user = User::factory()->create();

    postJson('/api/tokens/create', [
        'email'    => $user->email,
        'password' => 'wrong',
    ])
    ->assertStatus(422); // バリデーションエラー
});

// ─────────────────────────────────────────
// タスク一覧
// ─────────────────────────────────────────
test('authenticated user can get task list', function () {
    [$user, $project, $token] = setupApi();

    Task::factory(3)->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
    ]);

    withToken($token)
        ->getJson("/api/v1/projects/{$project->id}/tasks")
        ->assertStatus(200)
        ->assertJsonCount(3, 'data');  // data 配列に3件
});

test('unauthenticated user cannot access api', function () {
    [, $project] = setupApi();

    getJson("/api/v1/projects/{$project->id}/tasks")
        ->assertStatus(401);
});

// ─────────────────────────────────────────
// タスク作成
// ─────────────────────────────────────────
test('user can create a task via api', function () {
    [$user, $project, $token] = setupApi();

    withToken($token)
        ->postJson("/api/v1/projects/{$project->id}/tasks", [
            'title'    => 'API テストタスク',
            'priority' => 'high',
            'status'   => 'todo',
        ])
        ->assertStatus(201)  // Created
        ->assertJsonPath('data.title', 'API テストタスク')
        ->assertJsonPath('data.priority', 'high');

    assertDatabaseHas('tasks', ['title' => 'API テストタスク']);
});

test('task title is required', function () {
    [$user, $project, $token] = setupApi();

    withToken($token)
        ->postJson("/api/v1/projects/{$project->id}/tasks", [
            'title' => '',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title']);
});

// ─────────────────────────────────────────
// タスク更新（PATCH）
// ─────────────────────────────────────────
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

    assertDatabaseHas('tasks', [
        'id'     => $task->id,
        'status' => 'in_progress',
    ]);
});

// ─────────────────────────────────────────
// タスク削除
// ─────────────────────────────────────────
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
