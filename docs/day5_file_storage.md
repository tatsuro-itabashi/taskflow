# Day 5 — ファイル管理・Storage

Laravel 12 の Storage ファサードを使って、アバター画像アップロードとタスク添付ファイル機能を実装する手順です。

---

## 前提条件

- Day 4 までの実装が完了している（`projects` テーブルが存在する）
- `php artisan migrate:status` で既存マイグレーションが `Ran` 状態

---

## 完成イメージ

- プロフィールページでアバター画像をアップロード・削除できる
- アバター未設定時は Gravatar をデフォルト表示する
- タスクにファイル（PDF・画像・Word など）を添付できる
- 添付ファイルはアップロードした本人のみ削除できる

---

## PART 1 — Storage の仕組みを理解する

Laravel のストレージは **ディスク** という概念で管理されます。

```
storage/
└── app/
    ├── private/   ← local ディスク（外部から直接アクセス不可）
    └── public/    ← public ディスク（シンボリックリンク経由で公開）

public/
└── storage → ../storage/app/public  ← storage:link で作るシンボリックリンク
```

### ディスクの種類（config/filesystems.php）

| ディスク名 | 保存先 | 外部公開 | 用途 |
|-----------|--------|---------|------|
| `local` | `storage/app/private/` | ❌ | 非公開ファイル |
| `public` | `storage/app/public/` | ✅ | 画像・添付ファイルなど |
| `s3` | AWS S3 / 互換ストレージ | 設定次第 | 本番環境での大容量ファイル |

### シンボリックリンクの作成（初回のみ）

```bash
php artisan storage:link
```

これで `storage/app/public/` 内のファイルが `http://localhost:8000/storage/` で公開されます。

---

## PART 2 — アバター画像アップロードを実装する

### AvatarController を作成する

```bash
php artisan make:controller AvatarController
```

`app/Http/Controllers/AvatarController.php`：

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AvatarController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => [
                'required',
                'image',               // jpeg / png / gif / webp のみ許可
                'mimes:jpeg,png,webp', // 拡張子をさらに限定
                'max:2048',            // 2MB 以内
            ],
        ]);

        $user = $request->user();

        // 古いアバターが存在すれば先に削除（ストレージを無駄に使わない）
        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        // store() がランダムなファイル名を自動生成して保存してくれる
        // 保存先例: storage/app/public/avatars/AbCdEfGh1234.jpg
        $path = $request->file('avatar')->store('avatars', 'public');

        $user->update(['avatar' => $path]);

        return redirect()->route('profile.edit')
            ->with('success', 'アバターを更新しました');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
            $user->update(['avatar' => null]);
        }

        return redirect()->route('profile.edit')
            ->with('success', 'アバターを削除しました');
    }
}
```

**ポイント：`store()` メソッド**
- 第1引数：保存先ディレクトリ
- 第2引数：使用するディスク名
- ファイル名は自動でランダム生成されるため、重複や上書きが起きない

### User モデルにアクセサを追加する

`app/Models/User.php` に追記：

```php
use Illuminate\Support\Facades\Storage;

protected $appends = ['avatar_url']; // JSON シリアライズ時に自動で含める

/**
 * アバターの公開 URL を返す
 * avatar が未設定の場合は Gravatar にフォールバック
 */
public function getAvatarUrlAttribute(): string
{
    if ($this->avatar) {
        return Storage::disk('public')->url($this->avatar);
    }

    // メールアドレスから Gravatar URL を生成
    $hash = md5(strtolower(trim($this->email)));
    return "https://www.gravatar.com/avatar/{$hash}?d=mp&s=200";
}
```

**アクセサの命名規則**  
`get` + `キャメルケースの属性名` + `Attribute` = `getAvatarUrlAttribute`  
→ `$user->avatar_url` でアクセスできる仮想プロパティになる

### ルートを追加する

`routes/web.php` の `auth` ミドルウェアグループに追記：

```php
use App\Http\Controllers\AvatarController;

Route::middleware('auth')->group(function () {
    Route::patch('/avatar', [AvatarController::class, 'update'])->name('avatar.update');
    Route::delete('/avatar', [AvatarController::class, 'destroy'])->name('avatar.destroy');
});
```

### プロフィールページにアップロードフォームを追加する

`resources/js/Pages/Profile/Edit.vue` に追記：

```vue
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

const avatarForm = useForm({ avatar: null as File | null })

const onFileChange = (e: Event) => {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) avatarForm.avatar = file
}

const uploadAvatar = () => {
  avatarForm.post(route('avatar.update'), {
    forceFormData: true, // ← ファイル送信時は必須
  })
}
</script>

<template>
  <!-- アバターアップロードセクション -->
  <section class="space-y-6">
    <header>
      <h2 class="text-lg font-medium text-gray-900">プロフィール画像</h2>
    </header>

    <div class="flex items-center gap-6">
      <img
        :src="auth.user.avatar_url"
        class="w-20 h-20 rounded-full object-cover"
        alt="アバター"
      />
      <form @submit.prevent="uploadAvatar" class="flex items-center gap-3">
        <input
          type="file"
          accept="image/jpeg,image/png,image/webp"
          @change="onFileChange"
          class="text-sm text-gray-600"
        />
        <button
          type="submit"
          :disabled="!avatarForm.avatar || avatarForm.processing"
          class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md
                 hover:bg-indigo-700 disabled:opacity-50"
        >
          アップロード
        </button>
      </form>
    </div>

    <p v-if="avatarForm.errors.avatar" class="text-sm text-red-600">
      {{ avatarForm.errors.avatar }}
    </p>
  </section>
</template>
```

**`forceFormData: true` が必要な理由**  
Inertia はデフォルトで JSON 形式でデータを送信します。ファイルを含む場合は `multipart/form-data` に切り替える必要があるため、このオプションが必須です。

---

## PART 3 — タスク・添付ファイルモデルを作成する

### モデルとマイグレーションを生成する

```bash
php artisan make:model Task -m
php artisan make:model Attachment -mf
```

### tasks テーブル

`database/migrations/xxxx_create_tasks_table.php`：

```php
public function up(): void
{
    Schema::create('tasks', function (Blueprint $table) {
        $table->id();
        $table->foreignId('project_id')->constrained()->cascadeOnDelete();
        $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('created_by')->constrained('users');
        $table->string('title');
        $table->text('description')->nullable();
        $table->enum('status', ['todo', 'in_progress', 'in_review', 'done'])->default('todo');
        $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
        $table->date('due_date')->nullable();
        $table->unsignedInteger('position')->default(0); // カンバンボードの表示順
        $table->timestamps();
    });
}
```

**ポイント：`nullOnDelete()`**  
担当者（assignee）が削除されても、タスク自体は残したい場合に使います。  
`cascadeOnDelete()` と使い分けるのが重要です。

| メソッド | 親レコード削除時の挙動 |
|---------|---------------------|
| `cascadeOnDelete()` | 子レコードも自動削除 |
| `nullOnDelete()` | 外部キーを NULL にする（子レコードは残る） |

### attachments テーブル

`database/migrations/xxxx_create_attachments_table.php`：

```php
public function up(): void
{
    Schema::create('attachments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('task_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('filename');             // 元のファイル名（表示用）
        $table->string('path');                 // Storage 内のパス（保存キー）
        $table->string('mime_type');            // 'image/jpeg', 'application/pdf' など
        $table->unsignedBigInteger('size');     // バイト数
        $table->timestamps();
    });
}
```

```bash
php artisan migrate
```

### Task モデル

`app/Models/Task.php`：

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'assignee_id', 'created_by',
        'title', 'description', 'status', 'priority', 'due_date', 'position',
    ];

    protected $casts = [
        'due_date' => 'date', // Carbon インスタンスとして扱える
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
```

### Attachment モデル

`app/Models/Attachment.php`：

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id', 'user_id', 'filename', 'path', 'mime_type', 'size',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** 公開 URL を返すアクセサ（$attachment->url でアクセス） */
    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    /** 画像ファイルかどうか判定 */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /** バイト数を人間が読める形式に変換（$attachment->human_size でアクセス） */
    public function getHumanSizeAttribute(): string
    {
        $bytes = $this->size;
        if ($bytes < 1024)           return $bytes . ' B';
        if ($bytes < 1024 * 1024)    return round($bytes / 1024, 1) . ' KB';
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
```

### Project モデルに tasks リレーションを追加する

`app/Models/Project.php` に追記：

```php
public function tasks(): HasMany
{
    return $this->hasMany(Task::class);
}
```

これにより Day 4 でコメントアウトしていた `ProjectController@index` の `withCount('tasks')` が使えるようになります：

```php
$projects = $workspace->projects()
    ->with('creator')
    ->withCount('tasks') // ← コメントアウトを解除
    ->latest()
    ->get();
```

---

## PART 4 — AttachmentController と Policy を実装する

### AttachmentController を作成する

```bash
php artisan make:controller AttachmentController
```

`app/Http/Controllers/AttachmentController.php`：

```php
<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    /** ファイルをアップロードしてタスクに紐付ける */
    public function store(Request $request, Task $task): RedirectResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240', // 10MB 以内
                'mimes:jpeg,png,webp,gif,pdf,zip,txt,doc,docx,xls,xlsx',
            ],
        ]);

        $uploadedFile = $request->file('file');

        // task_id ごとにフォルダを分けて整理
        $path = $uploadedFile->store("attachments/task_{$task->id}", 'public');

        $task->attachments()->create([
            'user_id'   => $request->user()->id,
            'filename'  => $uploadedFile->getClientOriginalName(), // 元のファイル名
            'path'      => $path,
            'mime_type' => $uploadedFile->getMimeType(),
            'size'      => $uploadedFile->getSize(),
        ]);

        return back()->with('success', 'ファイルを添付しました');
    }

    /** 添付ファイルを削除する */
    public function destroy(Attachment $attachment): RedirectResponse
    {
        $this->authorize('delete', $attachment); // Policy で本人確認

        if (Storage::disk('public')->exists($attachment->path)) {
            Storage::disk('public')->delete($attachment->path); // Storage から削除
        }

        $attachment->delete(); // DB から削除

        return back()->with('success', 'ファイルを削除しました');
    }
}
```

**重要：Storage と DB は必ずセットで削除する**  
`$attachment->delete()` だけでは DB のレコードは消えますが、実ファイルは `storage/` に残り続けます。必ず `Storage::delete()` を先に呼んでください。

### AttachmentPolicy を作成する

```bash
php artisan make:policy AttachmentPolicy --model=Attachment
```

`app/Policies/AttachmentPolicy.php`：

```php
/** アップロードした本人のみ削除できる */
public function delete(User $user, Attachment $attachment): bool
{
    return $user->id === $attachment->user_id;
}
```

### ルートを追加する

`routes/web.php` に追記：

```php
use App\Http\Controllers\AttachmentController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/tasks/{task}/attachments', [AttachmentController::class, 'store'])
        ->name('attachments.store');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])
        ->name('attachments.destroy');
});
```

---

## PART 5 — Tinker で動作確認する

```bash
php artisan tinker
```

```php
// テストタスクを作成
$project = App\Models\Project::first()
$task = $project->tasks()->create([
    'created_by' => 1,
    'title'      => 'テストタスク',
    'status'     => 'todo',
    'priority'   => 'medium',
    'position'   => 1,
])

// 添付ファイルをダミー作成
$attachment = $task->attachments()->create([
    'user_id'   => 1,
    'filename'  => 'sample.pdf',
    'path'      => 'attachments/task_1/sample.pdf',
    'mime_type' => 'application/pdf',
    'size'      => 204800,
])

// アクセサの確認
$attachment->url          // "http://localhost/storage/attachments/task_1/sample.pdf"
$attachment->human_size   // "200 KB"
$attachment->isImage()    // false（PDF なので正常）

// User アクセサの確認
$user = App\Models\User::where('email', 'dev@example.com')->first()
$user->avatar_url         // Gravatar URL（avatar 未設定時のフォールバック）

// withCount が動くか確認
App\Models\Project::withCount('tasks')->get()->pluck('tasks_count', 'name')
```

### Storage ファサードの操作一覧

```php
// ファイルの存在確認
Storage::disk('public')->exists('avatars/sample.jpg')

// public ディスク内の全ファイル一覧
Storage::disk('public')->allFiles()

// 公開 URL を取得
Storage::disk('public')->url('avatars/sample.jpg')

// ファイルを削除
Storage::disk('public')->delete('avatars/sample.jpg')
```

---

## ファイル変更サマリー

| ファイル | 変更内容 |
|---------|---------|
| `database/migrations/xxxx_create_tasks_table.php` | 新規作成：tasks テーブル（4ステータス・4優先度・position） |
| `database/migrations/xxxx_create_attachments_table.php` | 新規作成：attachments テーブル（filename / path / mime_type / size） |
| `app/Models/Task.php` | 新規作成：project / assignee / attachments リレーション |
| `app/Models/Attachment.php` | 新規作成：url / human_size アクセサ、isImage() メソッド |
| `app/Models/Project.php` | `tasks()` リレーションを追加 |
| `app/Models/User.php` | `getAvatarUrlAttribute()` アクセサ、`$appends` を追加 |
| `app/Http/Controllers/AvatarController.php` | 新規作成：アバターアップロード・削除 |
| `app/Http/Controllers/AttachmentController.php` | 新規作成：添付ファイルアップロード・削除 |
| `app/Policies/AttachmentPolicy.php` | 新規作成：本人のみ削除できるポリシー |
| `routes/web.php` | avatar / attachments のルートを追加 |

---

## よくあるエラーと対処法

### Tinker で `Call to undefined method tasks()` が出る

**原因：** モデルを変更する前に開いていた Tinker セッションが古いクラス定義をキャッシュしている。

**対処：** `exit` で Tinker を終了して再起動する。

```bash
> exit
php artisan tinker
```

> Tinker（PsySH）はセッション中にクラスをキャッシュするため、**モデルを変更したら必ず再起動**してください。

### ファイルアップロード後に画像が表示されない

**原因：** `storage:link` が未実行でシンボリックリンクが存在しない。

**対処：**
```bash
php artisan storage:link
ls -la public/storage  # シンボリックリンクを確認
```

### ファイルを削除したが Storage にファイルが残る

**原因：** `$attachment->delete()` だけを呼んで `Storage::delete()` を忘れている。

**対処：** 必ずセットで実行する。

```php
// NG（DB のレコードだけ消え、実ファイルが残る）
$attachment->delete();

// OK（Storage → DB の順で削除）
Storage::disk('public')->delete($attachment->path);
$attachment->delete();
```

---

## 学習ポイントまとめ

- **`Storage::disk('public')`** — `storage/app/public/` を操作するディスク。`storage:link` でブラウザから公開される
- **`$file->store('dir', 'disk')`** — ランダムなファイル名で自動保存。上書きや重複を防げる
- **`$file->getClientOriginalName()`** — ユーザーが選んだ元のファイル名を取得（表示用に保存しておく）
- **`getXxxAttribute()`** — モデルアクセサ。`$model->xxx` で呼び出せる仮想プロパティを定義できる
- **`$appends`** — アクセサを JSON シリアライズ時に自動で含めるプロパティ
- **`nullOnDelete()`** vs **`cascadeOnDelete()`** — 親削除時に子を NULL にするか、連鎖削除するかの使い分け
- **`forceFormData: true`** — Inertia でファイルを送信する際に必須のオプション
- **Storage と DB はセット削除** — `Storage::delete()` を忘れると孤立ファイルがたまる
