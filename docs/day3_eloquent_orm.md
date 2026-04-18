# Day 3 — Eloquent ORM・リレーション・Factory・Seeder

Laravel 12 の Eloquent ORM を使って、ワークスペース機能の土台となるモデル・マイグレーション・ファクトリ・シーダーを実装する手順です。

---

## 前提条件

- Day 2 までの実装が完了している（`users` テーブルに `avatar` / `provider` / `provider_id` が存在する）
- `php artisan migrate:status` で既存マイグレーションが `Ran` 状態

---

## 完成イメージ

```
workspaces          ← ワークスペース本体
workspace_user      ← 中間テーブル（多対多の関係 + role カラム）
workspace_invitations ← 招待リンク管理
```

リレーション図：

```
User ──< workspace_user >── Workspace
                │
                └── role: owner / admin / member

Workspace ──< WorkspaceInvitation
```

---

## PART 1 — モデル・マイグレーション・ファクトリを一括生成する

Laravel の `make:model` コマンドは `-mf` オプションで **モデル・マイグレーション・ファクトリを同時生成**できます。

```bash
php artisan make:model Workspace -mf
php artisan make:model WorkspaceInvitation -mf
```

中間テーブル `workspace_user` はモデル不要なため、マイグレーションのみ個別に作成します：

```bash
php artisan make:migration create_workspace_user_table
```

生成されるファイル：

```
app/Models/Workspace.php
app/Models/WorkspaceInvitation.php
database/migrations/xxxx_create_workspaces_table.php
database/migrations/xxxx_create_workspace_user_table.php
database/migrations/xxxx_create_workspace_invitations_table.php
database/factories/WorkspaceFactory.php
database/factories/WorkspaceInvitationFactory.php
```

---

## PART 2 — マイグレーションを実装する

### workspaces テーブル

`database/migrations/xxxx_create_workspaces_table.php`：

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();           // URL用の識別子（例：my-team）
            $table->string('color')->default('#6366f1'); // ワークスペースカラー
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
```

**ポイント：**
- `foreignId('owner_id')->constrained('users')` — `users.id` を参照する外部キー制約を設定する
- `cascadeOnDelete()` — ユーザーが削除されたらワークスペースも自動削除される
- `slug` — URL に使う英数字の識別子（ユニーク制約あり）

### workspace_user 中間テーブル

`database/migrations/xxxx_create_workspace_user_table.php`：

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_user', function (Blueprint $table) {
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'member'])->default('member');
            $table->timestamps();

            $table->primary(['workspace_id', 'user_id']); // 複合主キー
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_user');
    }
};
```

**ポイント：**
- `primary(['workspace_id', 'user_id'])` — 2カラムの組み合わせを主キーにする（同じユーザーが同じワークスペースに重複参加するのを防ぐ）
- `enum('role', [...])` — 入力できる値を限定する

### workspace_invitations テーブル

`database/migrations/xxxx_create_workspace_invitations_table.php`：

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token')->unique(); // メールで送る招待トークン（ユニーク）
            $table->enum('role', ['admin', 'member'])->default('member');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
    }
};
```

### マイグレーション実行

```bash
php artisan migrate
```

---

## PART 3 — モデルにリレーションを定義する

### Workspace モデル

`app/Models/Workspace.php`：

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Workspace extends Model
{
    use HasFactory;

    protected $fillable = ['owner_id', 'name', 'slug', 'color'];

    // ================================
    // リレーション
    // ================================

    /** オーナーユーザー（多対1） */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** メンバー一覧（多対多）＋中間テーブルの role も取得 */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user') // ← テーブル名を明示
                    ->withPivot('role')    // $member->pivot->role でアクセス可能
                    ->withTimestamps();
    }

    /** 招待一覧（1対多） */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    // ================================
    // クエリスコープ（絞り込み条件の再利用）
    // ================================

    /** 特定ユーザーが所属するワークスペースだけに絞る */
    public function scopeForUser($query, User $user)
    {
        return $query->whereHas('members', fn($q) => $q->where('user_id', $user->id));
    }

    // ================================
    // モデルイベント
    // ================================

    /** 作成時に slug を自動生成 */
    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            if (empty($workspace->slug)) {
                $workspace->slug = Str::slug($workspace->name) . '-' . Str::lower(Str::random(5));
            }
        });
    }
}
```

### User モデルにリレーションを追加

`app/Models/User.php` に追記：

```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

// 自分がオーナーのワークスペース（1対多）
public function ownedWorkspaces(): HasMany
{
    return $this->hasMany(Workspace::class, 'owner_id');
}

// 自分が所属するワークスペース（多対多・中間テーブル経由）
public function workspaces(): BelongsToMany
{
    return $this->belongsToMany(Workspace::class, 'workspace_user') // ← テーブル名を明示
                ->withPivot('role')
                ->withTimestamps();
}
```

### WorkspaceInvitation モデル

`app/Models/WorkspaceInvitation.php`：

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceInvitation extends Model
{
    use HasFactory;

    protected $fillable = ['workspace_id', 'email', 'token', 'role', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** 有効期限切れかどうか */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
```

---

## PART 4 — Factory でダミーデータを生成できるようにする

### WorkspaceFactory

`database/factories/WorkspaceFactory.php`：

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WorkspaceFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'owner_id' => User::factory(), // Workspace 作成と同時に User も自動生成
            'name'     => $name,
            'slug'     => Str::slug($name) . '-' . Str::lower(Str::random(5)),
            'color'    => $this->faker->hexColor(),
        ];
    }
}
```

**ポイント：`User::factory()` のネスト**

`'owner_id' => User::factory()` と書くと、Workspace を生成する際に User も自動で生成・紐付けされます。これを**リレーションを持つファクトリ**と呼びます。

---

## PART 5 — Seeder で開発用初期データを投入する

### WorkspaceSeeder の作成

```bash
php artisan make:seeder WorkspaceSeeder
```

`database/seeders/WorkspaceSeeder.php`：

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class WorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        // firstOrCreate で冪等性を確保（何度実行しても重複しない）
        $owner = User::firstOrCreate(
            ['email' => 'dev@example.com'],
            [
                'name'              => 'Dev User',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        // ワークスペースを3つ作成
        $workspaces = Workspace::factory(3)->create([
            'owner_id' => $owner->id,
        ]);

        // 各ワークスペースにメンバーを追加
        $workspaces->each(function (Workspace $workspace) use ($owner) {
            // オーナーを中間テーブルに追加
            $workspace->members()->attach($owner->id, ['role' => 'owner']);

            // ランダムなメンバーを2〜4人生成して追加
            $members = User::factory(rand(2, 4))->create();
            $members->each(function (User $member) use ($workspace) {
                $workspace->members()->attach($member->id, ['role' => 'member']);
            });
        });
    }
}
```

### DatabaseSeeder に登録

`database/seeders/DatabaseSeeder.php`：

```php
public function run(): void
{
    $this->call([
        WorkspaceSeeder::class,
    ]);
}
```

### Seeder 実行

```bash
# 通常実行
php artisan db:seed

# DB 全リセット + マイグレーション + Seeder を一括実行（開発時のみ）
php artisan migrate:fresh --seed
```

> ⚠️ `migrate:fresh` は全テーブルを DROP して再作成します。本番環境では絶対に使わないでください。

---

## PART 6 — Tinker でリレーションを確認する

```bash
php artisan tinker
```

```php
// 全ワークスペースを取得
>>> App\Models\Workspace::all()

// オーナー情報を取得（belongsTo）
>>> App\Models\Workspace::first()->owner

// メンバー一覧を取得（belongsToMany）
>>> App\Models\Workspace::first()->members

// 中間テーブルの role を確認
>>> App\Models\Workspace::first()->members->first()->pivot->role

// ユーザーが所属するワークスペース一覧（逆方向）
>>> App\Models\User::first()->workspaces

// スコープを使う
>>> $user = App\Models\User::first()
>>> App\Models\Workspace::forUser($user)->get()

// メンバー数も同時に取得（withCount）
>>> App\Models\Workspace::withCount('members')->get()->pluck('members_count', 'name')
```

---

## PART 7 — N+1 問題を理解する

### NG（N+1 が発生する書き方）

```php
$workspaces = App\Models\Workspace::all();
foreach ($workspaces as $ws) {
    echo $ws->owner->name; // ← ループのたびに SELECT が1本走る
}
// ワークスペースが10件あれば → 1（全件取得）+ 10（owner×10）= 11本の SQL が発行される
```

### OK（Eager Loading で解決）

```php
$workspaces = App\Models\Workspace::with('owner')->get();
foreach ($workspaces as $ws) {
    echo $ws->owner->name;
}
// SQL は 2本だけ（workspaces 取得 + users 取得）
```

`with()` に複数リレーションを指定することもできます：

```php
Workspace::with(['owner', 'members'])->get();
```

---

## よくあるエラーと対処法

### Table 'xxx.user_workspace' doesn't exist

```
SQLSTATE[42S02]: Base table or view not found: 1146 Table 'taskflow.user_workspace' doesn't exist
```

**原因：** Laravel の `belongsToMany` は中間テーブル名を**アルファベット順**で自動推測します。
`User` + `Workspace` → `user_workspace`（u < w）と推測しますが、実際のテーブルは `workspace_user`。

**対処：** リレーション定義でテーブル名を明示する。

```php
// 修正前（自動推測に任せる）
$this->belongsToMany(User::class)

// 修正後（テーブル名を明示）
$this->belongsToMany(User::class, 'workspace_user')
```

| モデルの組み合わせ | Laravel が推測するテーブル名 |
|---|---|
| `User` + `Workspace` | `user_workspace`（u < w） |
| `Post` + `Tag` | `post_tag` |
| `Role` + `User` | `role_user` |

### Duplicate entry for key 'users.users_email_unique'

```
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'dev@example.com'
```

**原因：** Seeder を複数回実行すると同じメールアドレスで INSERT しようとしてエラーになる。

**対処：** `create()` の代わりに `firstOrCreate()` を使う。

```php
// 修正前（毎回 INSERT → 2回目以降エラー）
$owner = User::factory()->create(['email' => 'dev@example.com']);

// 修正後（あれば取得、なければ作成 → 何度実行しても安全）
$owner = User::firstOrCreate(
    ['email' => 'dev@example.com'],
    ['name' => 'Dev User', 'password' => bcrypt('password')]
);
```

---

## ファイル変更サマリー

| ファイル | 変更内容 |
|---------|---------|
| `app/Models/Workspace.php` | 新規作成：owner / members / invitations リレーション、scopeForUser、slug 自動生成 |
| `app/Models/WorkspaceInvitation.php` | 新規作成：workspace リレーション、isExpired() メソッド |
| `app/Models/User.php` | `ownedWorkspaces` / `workspaces` リレーションを追加 |
| `database/migrations/xxxx_create_workspaces_table.php` | 新規作成 |
| `database/migrations/xxxx_create_workspace_user_table.php` | 新規作成（複合主キー） |
| `database/migrations/xxxx_create_workspace_invitations_table.php` | 新規作成 |
| `database/factories/WorkspaceFactory.php` | 実装：faker でダミーデータ生成 |
| `database/seeders/WorkspaceSeeder.php` | 新規作成：firstOrCreate + attach でデータ投入 |
| `database/seeders/DatabaseSeeder.php` | WorkspaceSeeder を呼び出すよう追記 |

---

## 学習ポイントまとめ

- **`make:model -mf`** — モデル・マイグレーション・ファクトリを一括生成できる
- **`belongsToMany(Model::class, 'テーブル名')`** — 中間テーブル名は Laravel の命名規則と異なる場合は明示する
- **`withPivot('role')`** — 中間テーブルの追加カラムを取得するために必要
- **`attach($id, ['role' => 'owner'])`** — 多対多の紐付け登録（中間テーブルへの INSERT）
- **`firstOrCreate()`** — Seeder は冪等性（何度実行しても同じ結果）を保つように書く
- **`with('relation')`** — Eager Loading で N+1 問題を解決する
- **`migrate:fresh --seed`** — 開発中の DB 全リセット＋再構築コマンド
