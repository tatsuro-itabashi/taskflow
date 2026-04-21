# Day 4 — コントローラ・FormRequest・Policy

Laravel 12 の RESTful コントローラ設計・FormRequest によるバリデーション分離・Policy によるロールベース認可を実装する手順です。プロジェクトの CRUD 機能を題材に進めます。

---

## 前提条件

- Day 3 までの実装が完了している（`workspaces` / `workspace_user` テーブルが存在する）
- `dev@example.com` ユーザーとワークスペースのシードデータが投入済み

---

## 完成イメージ

- `/workspaces/{id}/projects` でプロジェクト一覧・作成ができる
- ロール（owner / admin / member）によって操作できる内容が変わる
- バリデーションエラーはフォームの直下にリアルタイム表示される

### 認可マトリクス

| アクション | owner | admin | member |
|-----------|:-----:|:-----:|:------:|
| 一覧を見る | ✅ | ✅ | ✅ |
| 作成する | ✅ | ✅ | ❌ |
| 更新する | ✅ | ✅ | ❌ |
| 削除する | ✅ | ❌ | ❌ |

---

## PART 1 — Project モデルを作成する

### モデル・マイグレーション・ファクトリを一括生成

```bash
php artisan make:model Project -mf
```

### マイグレーションを実装する

`database/migrations/xxxx_create_projects_table.php`：

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color')->default('#6366f1');
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
```

```bash
php artisan migrate
```

### Project モデルを実装する

`app/Models/Project.php`：

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'name',
        'description',
        'color',
        'status',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

### Workspace モデルに逆リレーションを追加

`app/Models/Workspace.php` に追記：

```php
public function projects(): HasMany
{
    return $this->hasMany(Project::class);
}
```

---

## PART 2 — RESTful コントローラの設計を理解する

Laravel のリソースコントローラは **7つのアクション** で構成されます。

| メソッド | URI | アクション | 役割 |
|---------|-----|-----------|------|
| GET | `/projects` | `index` | 一覧表示 |
| GET | `/projects/create` | `create` | 作成フォーム表示 |
| POST | `/projects` | `store` | 新規保存 |
| GET | `/projects/{id}` | `show` | 詳細表示 |
| GET | `/projects/{id}/edit` | `edit` | 編集フォーム表示 |
| PUT/PATCH | `/projects/{id}` | `update` | 更新保存 |
| DELETE | `/projects/{id}` | `destroy` | 削除 |

`--resource` オプションでこの7メソッドのスケルトンが自動生成されます：

```bash
php artisan make:controller ProjectController --resource --model=Project
```

---

## PART 3 — FormRequest でバリデーションを分離する

### なぜ FormRequest を使うのか

コントローラにバリデーションを直書きすると肥大化します。  
FormRequest に切り出すことで「コントローラは処理の流れだけ」「バリデーションは Request クラスが責務を持つ」と役割が明確になります。

```bash
php artisan make:request StoreProjectRequest
php artisan make:request UpdateProjectRequest
```

### StoreProjectRequest（作成時）

`app/Http/Requests/StoreProjectRequest.php`：

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    /**
     * このリクエストを実行できるか
     * 詳細な認可は Policy に委ねるため、ここでは認証済みかのみチェック
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color'       => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    /** エラーメッセージの日本語化 */
    public function messages(): array
    {
        return [
            'name.required' => 'プロジェクト名は必須です',
            'name.max'      => 'プロジェクト名は255文字以内にしてください',
            'color.regex'   => 'カラーは #RRGGBB 形式で入力してください',
        ];
    }
}
```

### UpdateProjectRequest（更新時）

`app/Http/Requests/UpdateProjectRequest.php`：

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color'       => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'status'      => ['nullable', 'in:active,archived'],
        ];
    }
}
```

**ポイント：`validated()` メソッド**  
コントローラで `$request->validated()` を呼ぶと、バリデーションを通過したデータだけを配列で取得できます。`$request->all()` と違い、余分なキーが混入しないため安全です。

---

## PART 4 — Policy でロールベース認可を実装する

### Policy とは

「誰が何をできるか」というビジネスルールを1ファイルに集約する仕組みです。  
コントローラの if 分岐がなくなり、認可ロジックの変更が1箇所で済みます。

```bash
php artisan make:policy ProjectPolicy --model=Project
```

`app/Policies/ProjectPolicy.php`：

```php
<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

class ProjectPolicy
{
    /**
     * ワークスペース内のユーザーロールを取得するプライベートヘルパー
     */
    private function getRole(User $user, Workspace $workspace): ?string
    {
        return $workspace->members()
            ->where('user_id', $user->id)
            ->value('workspace_user.role'); // pivot カラムを直接取得
    }

    /** 一覧閲覧：メンバーなら全員OK */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $this->getRole($user, $workspace) !== null;
    }

    /** 作成：owner / admin のみ */
    public function create(User $user, Workspace $workspace): bool
    {
        return in_array($this->getRole($user, $workspace), ['owner', 'admin']);
    }

    /** 更新：owner / admin のみ */
    public function update(User $user, Project $project): bool
    {
        return in_array(
            $this->getRole($user, $project->workspace),
            ['owner', 'admin']
        );
    }

    /** 削除：owner のみ */
    public function delete(User $user, Project $project): bool
    {
        return $this->getRole($user, $project->workspace) === 'owner';
    }
}
```

**`value('workspace_user.role')` のポイント**  
`->value()` は1レコードの特定カラムだけを取得するメソッドです。コレクション全体を取得して `->pivot->role` でアクセスするより効率的です。

---

## PART 5 — コントローラを実装する

`app/Http/Controllers/ProjectController.php`：

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /** プロジェクト一覧 */
    public function index(Workspace $workspace): Response
    {
        $this->authorize('viewAny', [Project::class, $workspace]);

        $projects = $workspace->projects()
            ->with('creator')   // N+1 対策の Eager Loading
            ->latest()
            ->get();

        return Inertia::render('Projects/Index', [
            'workspace' => $workspace,
            'projects'  => $projects,
        ]);
    }

    /** プロジェクト作成 */
    public function store(StoreProjectRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('create', [Project::class, $workspace]);

        $workspace->projects()->create([
            ...$request->validated(), // バリデーション済みデータをスプレッド展開
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('workspaces.projects.index', $workspace)
            ->with('success', 'プロジェクトを作成しました');
    }

    /** プロジェクト更新 */
    public function update(UpdateProjectRequest $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $project->update($request->validated());

        return redirect()
            ->route('workspaces.projects.index', $workspace)
            ->with('success', 'プロジェクトを更新しました');
    }

    /** プロジェクト削除 */
    public function destroy(Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return redirect()
            ->route('workspaces.projects.index', $workspace)
            ->with('success', 'プロジェクトを削除しました');
    }
}
```

**`$this->authorize()` の仕組み**  
Policy のメソッドを呼び出し、`false` が返ると自動で **403 Forbidden** レスポンスを返します。コントローラ内に `if (!can) { abort(403) }` を書く必要がなくなります。

**`...$request->validated()` のスプレッド展開**  
`validated()` が返す配列 `['name' => '...', 'color' => '...']` を `...` で展開して `create_by` とマージしています。PHP 8.1 以降で利用可能な書き方です。

---

## PART 6 — ルートを設定する

`routes/web.php` に追記：

```php
use App\Http\Controllers\ProjectController;

Route::middleware(['auth', 'verified'])->group(function () {

    Route::resource('workspaces.projects', ProjectController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->shallow(); // /projects/{id} で直接アクセス可能にする
});
```

生成されるルート（`php artisan route:list --path=projects` で確認）：

```
GET|HEAD   workspaces/{workspace}/projects    workspaces.projects.index
POST       workspaces/{workspace}/projects    workspaces.projects.store
PUT|PATCH  projects/{project}                 projects.update
DELETE     projects/{project}                 projects.destroy
```

**`shallow()` とは？**  
ネストしたリソースルートで、親 ID が不要なアクション（update / destroy）の URI を短くできるオプションです。  
`/workspaces/{workspace}/projects/{project}` → `/projects/{project}` に短縮されます。

---

## PART 7 — Vue ページを作成する

```bash
mkdir -p resources/js/Pages/Projects
```

`resources/js/Pages/Projects/Index.vue`：

```vue
<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm, router } from '@inertiajs/vue3'

interface Project {
  id: number
  name: string
  description: string | null
  color: string
  status: string
  creator: { id: number; name: string }
}

interface Workspace {
  id: number
  name: string
  slug: string
}

const props = defineProps<{
  workspace: Workspace
  projects: Project[]
}>()

// Inertia のフォームヘルパー（バリデーションエラーの管理も自動）
const form = useForm({
  name: '',
  description: '',
  color: '#6366f1',
})

const submit = () => {
  form.post(route('workspaces.projects.store', props.workspace.id), {
    onSuccess: () => form.reset(),
  })
}

const deleteProject = (project: Project) => {
  if (confirm(`「${project.name}」を削除しますか？`)) {
    router.delete(route('workspaces.projects.destroy', [props.workspace.id, project.id]))
  }
}
</script>

<template>
  <Head :title="`${workspace.name} - プロジェクト`" />

  <AuthenticatedLayout>
    <template #header>
      <h2 class="text-xl font-semibold text-gray-800">
        {{ workspace.name }} / プロジェクト
      </h2>
    </template>

    <div class="py-8 max-w-4xl mx-auto px-4">

      <!-- プロジェクト作成フォーム -->
      <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h3 class="text-lg font-medium mb-4">新規プロジェクト</h3>
        <form @submit.prevent="submit" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">プロジェクト名 *</label>
            <input
              v-model="form.name"
              type="text"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
              placeholder="例：ウェブサイトリニューアル"
            />
            <!-- FormRequest のエラーが自動でここに表示される -->
            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
              {{ form.errors.name }}
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">説明</label>
            <textarea
              v-model="form.description"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
              rows="2"
            />
          </div>

          <div class="flex items-center gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">カラー</label>
              <input v-model="form.color" type="color" class="mt-1 h-9 w-16 rounded cursor-pointer" />
            </div>
            <button
              type="submit"
              :disabled="form.processing"
              class="mt-5 px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 disabled:opacity-50"
            >
              作成する
            </button>
          </div>
        </form>
      </div>

      <!-- プロジェクト一覧 -->
      <div class="space-y-3">
        <div
          v-for="project in projects"
          :key="project.id"
          class="bg-white rounded-lg shadow p-5 flex items-center justify-between"
        >
          <div class="flex items-center gap-3">
            <span
              class="w-3 h-3 rounded-full flex-shrink-0"
              :style="{ backgroundColor: project.color }"
            />
            <div>
              <p class="font-medium text-gray-900">{{ project.name }}</p>
              <p class="text-sm text-gray-500">作成者：{{ project.creator.name }}</p>
            </div>
          </div>
          <button
            @click="deleteProject(project)"
            class="text-sm text-red-500 hover:text-red-700"
          >
            削除
          </button>
        </div>

        <p v-if="projects.length === 0" class="text-center text-gray-400 py-8">
          プロジェクトがまだありません
        </p>
      </div>

    </div>
  </AuthenticatedLayout>
</template>
```

---

## PART 8 — Policy の動作を Tinker で確認する

```bash
php artisan tinker
```

```php
$policy    = app(App\Policies\ProjectPolicy::class);
$owner     = App\Models\User::where('email', 'dev@example.com')->first();
$workspace = App\Models\Workspace::where('owner_id', $owner->id)->first();
$member    = $workspace->members()->wherePivot('role', 'member')->first();
$project   = $workspace->projects()->first();

// owner の認可チェック
$policy->viewAny($owner, $workspace);  // true
$policy->create($owner, $workspace);   // true
$policy->update($owner, $project);     // true
$policy->delete($owner, $project);     // true

// member の認可チェック
$policy->viewAny($member, $workspace); // true  （閲覧は全員OK）
$policy->create($member, $workspace);  // false （作成は不可）
$policy->update($member, $project);    // false （更新は不可）
$policy->delete($member, $project);    // false （削除は不可）
```

期待通りの結果：

```
=== ProjectPolicy 認可チェック ===
[owner] viewAny : OK
[owner] create  : OK
[owner] update  : OK
[owner] delete  : OK
---
[member] viewAny: OK
[member] create : NG
[member] update : NG
[member] delete : NG
```

---

## ファイル変更サマリー

| ファイル | 変更内容 |
|---------|---------|
| `database/migrations/xxxx_create_projects_table.php` | 新規作成：projects テーブル定義 |
| `app/Models/Project.php` | 新規作成：workspace / creator リレーション |
| `app/Models/Workspace.php` | `projects()` リレーションを追加 |
| `app/Http/Requests/StoreProjectRequest.php` | 新規作成：作成バリデーション |
| `app/Http/Requests/UpdateProjectRequest.php` | 新規作成：更新バリデーション |
| `app/Policies/ProjectPolicy.php` | 新規作成：ロールベース認可ルール |
| `app/Http/Controllers/ProjectController.php` | 新規作成：CRUD 4アクション |
| `routes/web.php` | `workspaces.projects` リソースルートを追加 |
| `resources/js/Pages/Projects/Index.vue` | 新規作成：一覧・作成フォーム画面 |

---

## よくあるエラーと対処法

### This action is unauthorized. (403)

**原因：** `$this->authorize()` で Policy が `false` を返した。

**確認方法：**
```bash
php artisan tinker
# Policy を直接呼んで true/false を確認する
>>> app(App\Policies\ProjectPolicy::class)->create($user, $workspace)
```

ロールが正しく `workspace_user` テーブルに入っているかも確認してください：
```bash
>>> $workspace->members()->withPivot('role')->get()->pluck('pivot.role', 'email')
```

### Route [workspaces.projects.index] not defined.

**原因：** `routes/web.php` への追記漏れ、または `php artisan route:cache` のキャッシュが古い。

**対処：**
```bash
php artisan route:clear
php artisan route:list --path=projects
```

---

## 学習ポイントまとめ

- **ResourceController** — `--resource` で7アクションのスケルトンが自動生成。`only()` で使うアクションを絞れる
- **FormRequest** — バリデーションをコントローラから分離。`validated()` でバリデーション済みデータのみ安全に取得できる
- **`messages()`** — FormRequest 内でエラーメッセージを日本語化できる
- **Policy** — 認可ロジックを1ファイルに集約。`$this->authorize()` で Policy を呼び出し、NG なら自動で 403 を返す
- **`shallow()`** — ネストリソースの URI を短縮するオプション
- **`...$request->validated()`** — スプレッド演算子でバリデーション済みデータを別の配列とマージできる
- **`value('pivot.column')`** — コレクション全体を取得せず、特定カラムだけを1クエリで取得できる
