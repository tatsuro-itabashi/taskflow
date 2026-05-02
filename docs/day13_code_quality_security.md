# Day 13 — コード品質＆セキュリティ

Laravel Pint（コード整形）と Larastan（静的解析）でコード品質を高め、  
セキュリティの観点から実装を見直します。

---

## 今日のゴール

```
【コード品質】
  - Laravel Pint   → PSR-12 準拠のコードスタイルに自動整形
  - Larastan       → 型エラー・未定義メソッドなどをコード実行前に検出

【セキュリティ】
  - Mass Assignment 保護の確認
  - SQL インジェクション対策の確認
  - 認可漏れ（認証なしでアクセスできるルート）の確認
  - 機密情報の漏洩チェック
```

---

## PART 1 — Laravel Pint でコードを整形する

### Pint とは

Laravel 公式のコードフォーマッター。PHP CS Fixer をベースに、Laravel スタイルに合わせた設定が同梱されています。  
**Laravel 9 以降はデフォルトで `require-dev` に含まれています。**

```bash
# インストール済み確認
./vendor/bin/pint --version
```

### 基本的な使い方

```bash
# 全ファイルを整形（変更が自動保存される）
./vendor/bin/pint

# 実際に変更せず、何が変わるかだけ確認する（dry-run）
./vendor/bin/pint --test

# 特定のファイル・ディレクトリのみ
./vendor/bin/pint app/Models
./vendor/bin/pint app/Http/Controllers/Api/TaskController.php

# git で変更されたファイルのみ整形
./vendor/bin/pint --dirty
```

### `pint.json` — 整形ルールの設定

```json
{
    "preset": "laravel",
    "rules": {
        "array_syntax": { "syntax": "short" },
        "ordered_imports": { "sort_algorithm": "alpha" },
        "no_unused_imports": true,
        "not_operator_with_successor_space": true,
        "trailing_comma_in_multiline": true
    }
}
```

**主なプリセット：**

| プリセット | 説明 |
|-----------|------|
| `laravel` | Laravel 公式スタイル（デフォルト） |
| `psr12`   | PSR-12 準拠 |
| `symfony`  | Symfony スタイル |

### 実行結果（今回）

```bash
./vendor/bin/pint --test
# → {"result":"pass"}  ← エラー 0、変更不要
```

---

## PART 2 — Larastan で静的解析する

### Larastan とは

PHPStan の Laravel 向けプラグイン。**コードを実行せずに** 型エラー・未定義メソッド・null 参照などのバグを事前に検出します。

### インストール

```bash
composer require larastan/larastan --dev
```

### 設定ファイル

**`phpstan.neon`（プロジェクトルート）：**

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    bootstrapFiles:
        - phpstan-bootstrap.php

    paths:
        - app

    # レベル 0（最も緩い）〜 9（最も厳しい）
    # 最初は 5 からスタートして徐々に上げるのがおすすめ
    level: 5
```

**`phpstan-bootstrap.php`（プロジェクトルート）：**

```php
<?php

ini_set('memory_limit', '512M');
```

`bootstrapFiles` を分けることで、メモリ設定などの初期化処理を `phpstan.neon` から分離できます。

### 実行

```bash
./vendor/bin/phpstan analyse
```

### 実行結果（今回）

```
Note: Using configuration file /path/to/phpstan.neon.

 [OK] No errors
```

**Level 5 でエラー 0** を達成。これは Larastan の推奨レベルを満たしています。

### レベルの目安

| レベル | 検出内容 |
|--------|---------|
| 0 | 基本的な構文エラー |
| 1 | 未定義クラス・メソッド |
| 3 | 戻り値の型チェック |
| **5** | **null 参照・型の不一致（今回のゴール）** |
| 7 | より厳格な型チェック |
| 9 | 完全な型安全（厳格すぎることも多い） |

---

## PART 3 — Larastan エラーを修正するパターン

Larastan でよく出る警告と、今回の修正例です。

### ① PHPDoc でリレーションの戻り値型を明示する

```php
// ❌ 型が不明（Larastan が警告を出す）
public function ownedWorkspaces(): HasMany
{
    return $this->hasMany(Workspace::class, 'owner_id');
}

// ✅ ジェネリクスで型を明示（今回の実装）
/**
 * @return HasMany<Workspace, $this>
 */
public function ownedWorkspaces(): HasMany
{
    return $this->hasMany(Workspace::class, 'owner_id');
}

/**
 * @return BelongsToMany<Workspace, $this>
 */
public function workspaces(): BelongsToMany
{
    return $this->belongsToMany(Workspace::class, 'workspace_user')
        ->withPivot('role')
        ->withTimestamps();
}
```

### ② nullable な値に対する型アノテーション

```php
// ❌ Storage::disk() の戻り値が FilesystemAdapter か不明
$disk = Storage::disk('public');

// ✅ @var でキャストして型を明示
/** @var FilesystemAdapter $disk */
$disk = Storage::disk('public');

return $disk->url($this->avatar);
```

### ③ メモリ不足への対応（phpstan-bootstrap.php）

Larastan はアプリ全体を解析するためメモリを多く使います。

```php
// phpstan-bootstrap.php
ini_set('memory_limit', '512M');
```

---

## PART 4 — セキュリティチェックリスト

### ① Mass Assignment 保護 ✅

`$fillable` で許可するカラムを明示的に定義：

```php
// app/Models/User.php
protected $fillable = [
    'name',
    'email',
    'password',
    'avatar',
    'provider',
    'provider_id',
];

// app/Models/Task.php
protected $fillable = [
    'project_id', 'assignee_id', 'created_by',
    'title', 'description', 'status', 'priority',
    'due_date', 'position',
];
```

`$guarded = []`（全カラム許可）は使用していません。

### ② SQL インジェクション対策 ✅

Laravel の Eloquent / クエリビルダーはプレースホルダーを自動で使うため安全です：

```php
// ✅ 安全（Eloquent は自動でバインディング）
User::where('email', $email)->first();

// ✅ 安全（クエリビルダーもバインディング）
DB::table('users')->where('email', '=', $email)->first();

// ❌ 危険（生 SQL にユーザー入力を直接埋め込む — このプロジェクトでは使用していない）
DB::select("SELECT * FROM users WHERE email = '{$email}'");
```

### ③ 認可漏れの確認 ✅

全ルートに `auth` ミドルウェアが付いているか確認：

```bash
php artisan route:list --columns=method,uri,middleware
```

Controller の `authorize()` 呼び出しの確認：

```bash
grep -rn "authorize(" app/Http/Controllers/
```

各 Controller のチェック状況：

| Controller | 認証 | 認可 |
|-----------|------|------|
| `ProjectController` | `auth` ミドルウェア | `$this->authorize()` |
| `AttachmentController` | `auth` ミドルウェア | `$this->authorize()` |
| `Api/TaskController` | `auth:sanctum` | ルートスコープ |
| `Api/NotificationController` | `auth:sanctum` | ユーザー自身のデータのみ取得 |

### ④ 機密情報の漏洩防止 ✅

`app/Models/User.php` の `$hidden` に `provider_token` を追加：

```php
protected $hidden = [
    'password',
    'remember_token',
    'provider_token', // ← OAuth トークンを API レスポンスから除外
];
```

これにより `User` モデルを JSON にシリアライズしたとき、OAuth トークンが含まれなくなります。

### ⑤ レート制限の確認 ✅（Day 6 実装済み）

```php
// app/Providers/AppServiceProvider.php
RateLimiter::for('api', function (Request $request) {
    return $request->user()
        ? Limit::perMinute(120)->by($request->user()->id)
        : Limit::perMinute(30)->by($request->ip());
});
```

### ⑥ `.env` が Git に含まれていないか確認 ✅

```bash
cat .gitignore | grep "^\.env"   # → .env が含まれていることを確認
git ls-files | grep "^\.env"     # → 何も出力されなければ OK
```

### ⑦ パッケージの脆弱性チェック

```bash
composer audit
```

```
No security vulnerability advisories found.  ✅
```

---

## CI への組み込み（発展）

将来 GitHub Actions で自動チェックする設定例：

`.github/workflows/quality.yml`：

```yaml
name: Code Quality

on: [push, pull_request]

jobs:
  quality:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Install dependencies
        run: composer install --no-interaction

      - name: Run Pint (dry-run)
        run: ./vendor/bin/pint --test

      - name: Run Larastan
        run: ./vendor/bin/phpstan analyse --no-progress
```

コードをプッシュするたびに自動でチェックが走り、問題があれば PR がマージできなくなります。

---

## よくあるエラーと対処法

### Larastan がメモリ不足で落ちる

**原因：** デフォルトのメモリ制限では大規模アプリの解析が難しい  
**対処：** `phpstan-bootstrap.php` で `ini_set('memory_limit', '512M')` を設定する

### Larastan が Eloquent のマジックメソッドを認識できない

**原因：** `User::where()` のような動的メソッドは静的解析が難しい  
**対処：** IDE Helper で型情報ファイルを自動生成する

```bash
composer require barryvdh/laravel-ide-helper --dev
php artisan ide-helper:generate
php artisan ide-helper:models --nowrite
```

### Pint が意図しない箇所を変更する

**対処：** `pint.json` の `rules` でルールを無効化する

```json
{
    "preset": "laravel",
    "rules": {
        "not_operator_with_successor_space": false
    }
}
```

---

## ファイル変更サマリー

| ファイル | 変更内容 |
|---------|---------|
| `pint.json` | 新規作成：Pint の整形ルール設定 |
| `phpstan.neon` | 新規作成：Larastan の解析設定（level: 5） |
| `phpstan-bootstrap.php` | 新規作成：メモリ制限の設定 |
| `app/Models/User.php` | リレーションに PHPDoc 型注釈を追加、`$hidden` に `provider_token` を追加 |
| `app/Policies/ProjectPolicy.php` | Pint による自動整形 |
| `app/Providers/AppServiceProvider.php` | Pint による自動整形 |

---

## 最終チェック結果

| ツール | 結果 |
|--------|------|
| `./vendor/bin/pint --test` | ✅ `pass`（変更不要） |
| `./vendor/bin/phpstan analyse` | ✅ `No errors`（Level 5） |
| `composer audit` | ✅ `No security vulnerability advisories found` |

---

## 学習ポイントまとめ

- **Laravel Pint** — Laravel 公式のコードフォーマッター。`--test` で dry-run、`--dirty` で git 差分ファイルのみ整形。`pint.json` でルールをカスタマイズ
- **Larastan** — PHPStan の Laravel 向けプラグイン。コード実行前に型エラーを検出。Level 5 を目標に段階的に上げる
- **PHPDoc のジェネリクス** — `HasMany<Workspace, $this>` のように型引数を書くことで Larastan が正確に型を認識できる
- **`phpstan-bootstrap.php`** — Larastan の初期化処理を分離するファイル。メモリ制限などを設定する
- **Mass Assignment** — `$fillable` で許可カラムを明示的にリストアップ。`$guarded = []` は危険なので使わない
- **`$hidden`** — API レスポンスに含めてはいけないカラム（password, トークン類）をモデルで除外する
- **`composer audit`** — 依存パッケージの既知の脆弱性を定期的にチェックする。CI に組み込むと自動化できる
