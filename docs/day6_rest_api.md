# Day 6 — REST API・Sanctum・Scramble

Laravel 12 で REST API を構築する手順です。API Resource によるレスポンス整形・Sanctum によるトークン認証・Rate Limiting・Scramble による API ドキュメント自動生成を実装します。

---

## 前提条件

- Day 5 までの実装が完了している（`tasks` / `attachments` テーブルが存在する）
- `laravel/sanctum` が `composer.json` に含まれている

---

## 完成イメージ

```
POST   /api/tokens/create                       ← トークン発行（認証不要）
GET    /api/user                                ← ログインユーザー情報
GET    /api/v1/projects/{id}/tasks              ← タスク一覧
POST   /api/v1/projects/{id}/tasks              ← タスク作成
GET    /api/v1/projects/{id}/tasks/{task}       ← タスク詳細
PATCH  /api/v1/projects/{id}/tasks/{task}       ← タスク更新
DELETE /api/v1/projects/{id}/tasks/{task}       ← タスク削除
GET    /docs/api                                ← Scramble ドキュメント UI
```

---

## PART 1 — API ルートを有効化する

### Laravel 12 の重要ポイント

Laravel 11/12 では `routes/api.php` は**デフォルトで存在しません**。以下のコマンドで一括セットアップします：

```bash
php artisan install:api
```

このコマンドが行うこと：
- `routes/api.php` を作成
- `personal_access_tokens` テーブルのマイグレーションを追加・実行
- `bootstrap/app.php` に API ルートを自動登録

実行後の `bootstrap/app.php`：

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',  // ← 自動追加される
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

### User モデルに HasApiTokens を追加する

`app/Models/User.php`：

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable; // HasApiTokens を先頭に追加
    // ...
}
```

---

## PART 2 — API Resource でレスポンス形式を統一する

### API Resource とは

モデルを JSON に変換するルールを定義するクラスです。  
`$model->toArray()` をそのまま返すのではなく、**必要なフィールドだけを・整形して**返すことで以下を実現します：

- パスワードなど機密フィールドの漏洩防止
- レスポンス形式の一元管理（フィールド名の変更が1箇所で済む）
- リレーションの条件付き展開

```bash
php artisan make:resource TaskResource
php artisan make:resource ProjectResource
```

### TaskResource

`app/Http/Resources/TaskResource.php`：

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'status'      => $this->status,
            'priority'    => $this->priority,
            'due_date'    => $this->due_date?->format('Y-m-d'), // Carbon → 文字列に変換
            'position'    => $this->position,

            // リレーションがロード済みの場合のみ含める
            'assignee' => $this->whenLoaded('assignee', fn() => [
                'id'         => $this->assignee->id,
                'name'       => $this->assignee->name,
                'avatar_url' => $this->assignee->avatar_url,
            ]),
            'attachments_count' => $this->whenCounted('attachments'),

            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
```

### ProjectResource

`app/Http/Resources/ProjectResource.php`：

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'color'       => $this->color,
            'status'      => $this->status,
            'tasks_count' => $this->whenCounted('tasks'),
            'creator'     => $this->whenLoaded('creator', fn() => [
                'id'   => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
```

**`whenLoaded()` / `whenCounted()` の重要性**

| メソッド | 動作 |
|---------|------|
| `whenLoaded('assignee')` | `with('assignee')` でロード済みの場合のみ含める |
| `whenCounted('attachments')` | `withCount('attachments')` を呼んだ場合のみ含める |

これにより「一覧では件数だけ、詳細では中身も含める」という使い分けが可能です。

---

## PART 3 — API コントローラを実装する

`--api` オプションで `create` / `edit`（フォーム表示用）を除いた5メソッドを生成します：

```bash
php artisan make:controller Api/TaskController --api
```

`app/Http/Controllers/Api/TaskController.php`：

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController extends Controller
{
    /** タスク一覧を返す */
    public function index(Project $project): AnonymousResourceCollection
    {
        $tasks = $project->tasks()
            ->with('assignee')
            ->withCount('attachments')
            ->orderBy('position')
            ->get();

        return TaskResource::collection($tasks);
    }

    /** タスクを作成する */
    public function store(Request $request, Project $project): TaskResource
    {
        $validated = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status'      => ['nullable', 'in:todo,in_progress,in_review,done'],
            'priority'    => ['nullable', 'in:low,medium,high,urgent'],
            'due_date'    => ['nullable', 'date'],
            'assignee_id' => ['nullable', 'exists:users,id'],
        ]);

        $task = $project->tasks()->create([
            ...$validated,
            'created_by' => $request->user()->id,
            'position'   => $project->tasks()->max('position') + 1,
        ]);

        return new TaskResource($task->load('assignee'));
    }

    /** タスク詳細を返す */
    public function show(Project $project, Task $task): TaskResource
    {
        return new TaskResource(
            $task->load(['assignee', 'attachments'])
        );
    }

    /** タスクを更新する */
    public function update(Request $request, Project $project, Task $task): TaskResource
    {
        $validated = $request->validate([
            'title'       => ['sometimes', 'string', 'max:255'], // ← PATCH 用
            'description' => ['nullable', 'string'],
            'status'      => ['sometimes', 'in:todo,in_progress,in_review,done'],
            'priority'    => ['sometimes', 'in:low,medium,high,urgent'],
            'due_date'    => ['nullable', 'date'],
            'assignee_id' => ['nullable', 'exists:users,id'],
            'position'    => ['sometimes', 'integer', 'min:0'],
        ]);

        $task->update($validated);

        return new TaskResource($task->fresh()->load('assignee'));
    }

    /** タスクを削除する */
    public function destroy(Project $project, Task $task): JsonResponse
    {
        $task->delete();
        return response()->json(['message' => 'タスクを削除しました']);
    }
}
```

**`sometimes` バリデーションルール**  
リクエストにそのキーが含まれる場合のみバリデーションを実行します。  
PATCH（部分更新）で「送ったフィールドだけ更新する」ときに必須です。

| ルール | 動作 |
|-------|------|
| `required` | 必ず存在しなければならない |
| `sometimes` | 存在する場合のみバリデーション（存在しなくても OK） |
| `nullable` | null を許容する |

---

## PART 4 — Rate Limiting を設定する

`app/Providers/AppServiceProvider.php` の `boot()` に追記：

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

public function boot(): void
{
    Vite::prefetch(concurrency: 3);

    RateLimiter::for('api', function (Request $request) {
        return $request->user()
            ? Limit::perMinute(120)->by($request->user()->id) // 認証済み：120回/分
            : Limit::perMinute(30)->by($request->ip());       // 未認証：IP で 30回/分
    });
}
```

**ポイント：認証済みとゲストで制限を変える**  
未認証リクエストは IP ベースで厳しく制限し、認証済みユーザーは緩和します。  
制限を超えると `429 Too Many Requests` が自動で返されます。

---

## PART 5 — API ルートを設定する

`routes/api.php`：

```php
<?php

use App\Http\Controllers\Api\TaskController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

// 認証確認エンドポイント
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// v1 グループ（バージョニング + 認証 + Rate Limiting）
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::apiResource('projects.tasks', TaskController::class);
});

// トークン発行（認証不要）
Route::post('/tokens/create', function (Request $request) {
    $request->validate([
        'email'    => 'required|email',
        'password' => 'required',
    ]);

    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['認証情報が正しくありません'],
        ]);
    }

    $token = $user->createToken(
        name: 'api-token',
        abilities: ['tasks:read', 'tasks:write'] // 権限スコープ
    );

    return ['token' => $token->plainTextToken];
});
```

**`prefix('v1')` でバージョニングする理由**  
将来 API の仕様を大きく変更する際、`/api/v2/` として並行稼働できます。最初から付けておくと後で困りません。

生成されるルート（`php artisan route:list --path=api` で確認）：

```
POST       api/tokens/create
GET|HEAD   api/user
GET|HEAD   api/v1/projects/{project}/tasks          projects.tasks.index
POST       api/v1/projects/{project}/tasks          projects.tasks.store
GET|HEAD   api/v1/projects/{project}/tasks/{task}   projects.tasks.show
PUT|PATCH  api/v1/projects/{project}/tasks/{task}   projects.tasks.update
DELETE     api/v1/projects/{project}/tasks/{task}   projects.tasks.destroy
GET|HEAD   docs/api                                 ← Scramble UI
GET|HEAD   docs/api.json                            ← OpenAPI JSON
```

---

## PART 6 — Scramble で API ドキュメントを自動生成する

```bash
composer require dedoc/scramble
php artisan vendor:publish --provider="Dedoc\Scramble\ScrambleServiceProvider" --tag="scramble-config"
```

ブラウザで確認：

```
http://localhost:8000/docs/api
```

Scramble はコントローラの型定義・戻り値の型・PHPDoc を自動解析して **OpenAPI 仕様のドキュメント** を生成します。追加設定なしで動作します。

---

## PART 7 — curl で動作確認する

### トークンを取得する

```bash
curl -X POST http://localhost:8000/api/tokens/create \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"dev@example.com","password":"password"}'

# レスポンス例
# {"token":"1|AbCdEfGhIjKlMnOpQrStUvWxYz"}
```

### タスク一覧を取得する

```bash
TOKEN="取得したトークンをここに"
PROJECT_ID=1

curl http://localhost:8000/api/v1/projects/${PROJECT_ID}/tasks \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Accept: application/json"
```

### タスクを作成する

```bash
curl -X POST http://localhost:8000/api/v1/projects/${PROJECT_ID}/tasks \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"title":"API から作ったタスク","priority":"high","status":"todo"}'
```

### タスクをステータス更新する（PATCH）

```bash
TASK_ID=1

curl -X PATCH http://localhost:8000/api/v1/projects/${PROJECT_ID}/tasks/${TASK_ID} \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"status":"in_progress"}'
```

### タスクを削除する

```bash
curl -X DELETE http://localhost:8000/api/v1/projects/${PROJECT_ID}/tasks/${TASK_ID} \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Accept: application/json"

# レスポンス
# {"message":"タスクを削除しました"}
```

---

## Tinker でトークンを確認する

```bash
php artisan tinker

# トークンを発行する
>>> $user = App\Models\User::where('email', 'dev@example.com')->first()
>>> $token = $user->createToken('test', ['tasks:read', 'tasks:write'])
>>> $token->plainTextToken  // "1|xxxx..." の形式で返る

# 発行済みトークン数を確認
>>> Laravel\Sanctum\PersonalAccessToken::count()

# トークンを失効させる（ログアウト相当）
>>> $user->tokens()->delete()
```

---

## ファイル変更サマリー

| ファイル | 変更内容 |
|---------|---------|
| `routes/api.php` | 新規作成：トークン発行 / user / v1 タスク CRUD ルート |
| `bootstrap/app.php` | `install:api` により `api:` ルートが自動追加 |
| `app/Models/User.php` | `HasApiTokens` トレイトを追加 |
| `app/Http/Resources/TaskResource.php` | 新規作成：タスクの JSON 変換ルール |
| `app/Http/Resources/ProjectResource.php` | 新規作成：プロジェクトの JSON 変換ルール |
| `app/Http/Controllers/Api/TaskController.php` | 新規作成：タスク CRUD（5アクション） |
| `app/Providers/AppServiceProvider.php` | Rate Limiting の設定を追加 |

---

## よくあるエラーと対処法

### 401 Unauthenticated

**原因：** `Authorization: Bearer` ヘッダーが未送信、またはトークンが失効している。

**対処：**
```bash
# ヘッダーが正しく付いているか確認
curl http://localhost:8000/api/v1/projects/1/tasks \
  -H "Authorization: Bearer 1|yourtoken" \
  -H "Accept: application/json"   # ← これがないと HTML が返ることもある
```

### 422 Unprocessable Content（バリデーションエラー）

**原因：** `Content-Type: application/json` が未送信で POST/PATCH を叩いている。

**対処：** POST・PATCH リクエスト時は必ず追加する。

```bash
-H "Content-Type: application/json"
```

### `routes/api.php` が存在しない

**原因：** `php artisan install:api` を実行していない。

**対処：**
```bash
php artisan install:api
php artisan migrate
```

---

## 学習ポイントまとめ

- **`install:api`** — Laravel 12 で API を使う前に必須のセットアップコマンド。`api.php` の作成から Sanctum のマイグレーションまで一括で行う
- **API Resource** — モデル → JSON の変換ルールを定義。機密フィールドの漏洩を防ぎ、レスポンス形式を一元管理できる
- **`whenLoaded()` / `whenCounted()`** — リレーションがロード済みの場合のみ含める。N+1 防止と組み合わせて使う
- **`sometimes`** — フィールドが存在する場合のみバリデーション。PATCH（部分更新）に必須
- **`createToken(name, abilities)`** — Sanctum のトークン発行。`abilities` で権限スコープを設定できる
- **`throttle:api`** — Rate Limiting ミドルウェア。`AppServiceProvider` でユーザーごとに制限を変えられる
- **Scramble** — PHPDoc・型定義から OpenAPI ドキュメントを自動生成。設定なしで `/docs/api` にアクセスできる
- **API バージョニング** — `prefix('v1')` を最初から付けておくと、将来の破壊的変更に対応しやすい
