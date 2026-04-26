<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    withoutVite();
});

// ─────────────────────────────────────────
// テスト用のヘルパー：認証済みユーザー + ワークスペース + プロジェクトをセットアップ
// ─────────────────────────────────────────
/**
 * @return array{User, Workspace}
 */
function setupWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, ['role' => 'owner']);

    return [$owner, $workspace];
}

// ─────────────────────────────────────────
// プロジェクト一覧
// ─────────────────────────────────────────
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
            ->has('projects', 3)  // projects に3件含まれるか
        );
});

test('guest cannot view project list', function () {
    [$owner, $workspace] = setupWorkspace();

    get(route('workspaces.projects.index', $workspace))
        ->assertRedirect('/login');  // 未認証はログインにリダイレクト
});

test('non-member cannot view project list', function () {
    [$owner, $workspace] = setupWorkspace();
    /** @var User $other */
    $other = User::factory()->create(); // ワークスペース未参加のユーザー

    actingAs($other)
        ->get(route('workspaces.projects.index', $workspace))
        ->assertStatus(403);
});

// ─────────────────────────────────────────
// プロジェクト作成
// ─────────────────────────────────────────
test('owner can create a project', function () {
    [$owner, $workspace] = setupWorkspace();

    actingAs($owner)
        ->post(route('workspaces.projects.store', $workspace), [
            'name'  => 'テストプロジェクト',
            'color' => '#6366f1',
        ])
        ->assertRedirect();

    // DB に保存されているか確認
    assertDatabaseHas('projects', [
        'name'         => 'テストプロジェクト',
        'workspace_id' => $workspace->id,
        'created_by'   => $owner->id,
    ]);
});

test('member cannot create a project', function () {
    [$owner, $workspace] = setupWorkspace();
    /** @var User $member */
    $member = User::factory()->create();
    $workspace->members()->attach($member->id, ['role' => 'member']);

    actingAs($member)
        ->post(route('workspaces.projects.store', $workspace), [
            'name' => 'メンバーが作成',
        ])
        ->assertStatus(403);

    // DB に保存されていないことを確認
    assertDatabaseMissing('projects', ['name' => 'メンバーが作成']);
});

test('project name is required', function () {
    [$owner, $workspace] = setupWorkspace();

    actingAs($owner)
        ->post(route('workspaces.projects.store', $workspace), [
            'name' => '', // 空文字
        ])
        ->assertSessionHasErrors(['name']); // バリデーションエラーがあるか
});

test('project name cannot exceed 255 characters', function () {
    [$owner, $workspace] = setupWorkspace();

    actingAs($owner)
        ->post(route('workspaces.projects.store', $workspace), [
            'name' => str_repeat('a', 256), // 256文字
        ])
        ->assertSessionHasErrors(['name']);
});

// ─────────────────────────────────────────
// プロジェクト削除
// ─────────────────────────────────────────
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
    /** @var User $member */
    $member = User::factory()->create();
    $workspace->members()->attach($member->id, ['role' => 'member']);

    $project = Project::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by'   => $owner->id,
    ]);

    actingAs($member)
        ->delete(route('workspaces.projects.destroy', [$workspace, $project]))
        ->assertStatus(403);

    // プロジェクトがまだ存在するか
    assertDatabaseHas('projects', ['id' => $project->id]);
});
