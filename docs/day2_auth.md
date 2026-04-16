# Day 2 — 認証実装ハンズオン

Laravel 12 + Breeze + Inertia.js + Vue 3 + Laravel Socialite を使って、
メール/パスワード認証と GitHub OAuth ログインを実装する手順です。

---

## 前提条件

- Laravel 12 プロジェクト作成済み
- `laravel/breeze` インストール済み（`breeze:install vue --typescript --pest` で導入）
- `php artisan migrate` 実行済み（`users` テーブルが存在する）
- MySQL が起動している

---

## 完成イメージ

- `/register` `/login` でメール認証が動作する
- ログイン画面に「GitHub でログイン」ボタンが表示される
- GitHub アカウントでログインすると `/dashboard` に遷移する
- OAuth ユーザーは `avatar` / `provider` / `provider_id` がDBに保存される

---

## PART 1 — Breeze の認証構造を理解する

### 認証ルートを確認する

`routes/auth.php` に認証関連のルートが自動生成されています。

```php
// 登録
Route::get('register', [RegisteredUserController::class, 'create']);
Route::post('register', [RegisteredUserController::class, 'store']);

// ログイン
Route::get('login', [AuthenticatedSessionController::class, 'create']);
Route::post('login', [AuthenticatedSessionController::class, 'store']);

// メール認証
Route::get('verify-email', EmailVerificationPromptController::class);
Route::get('verify-email/{id}/{hash}', VerifyEmailController::class);
```

### User モデルを確認する

`app/Models/User.php` に `implements MustVerifyEmail` があることを確認します。
これがメール認証を有効にするスイッチです。

```php
class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
```

---

## PART 2 — users テーブルに OAuth 用カラムを追加する

### マイグレーションファイルを作成する

```bash
php artisan make:migration add_oauth_columns_to_users_table
```

`database/migrations/xxxx_add_oauth_columns_to_users_table.php` が生成されます。

### マイグレーションを実装する

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('email');
            $table->string('provider')->nullable()->after('avatar');       // 'github', 'google' など
            $table->string('provider_id')->nullable()->after('provider');  // OAuth プロバイダが発行する一意のID
            $table->string('password')->nullable()->change();              // OAuth ユーザーはパスワード不要
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar', 'provider', 'provider_id']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
```

> **ポイント**：`change()` メソッドを使うには `doctrine/dbal` パッケージが必要です。

### doctrine/dbal をインストールしてマイグレーション実行

```bash
composer require doctrine/dbal
php artisan migrate
```

### User モデルの $fillable を更新する

`app/Models/User.php` の `$fillable` に追加します：

```php
protected $fillable = [
    'name',
    'email',
    'password',
    'avatar',       // 追加
    'provider',     // 追加
    'provider_id',  // 追加
];
```

---

## PART 3 — GitHub OAuth ログインを実装する

### 1. Socialite をインストールする

```bash
composer require laravel/socialite
```

### 2. GitHub で OAuth アプリを作成する

1. [https://github.com/settings/developers](https://github.com/settings/developers) にアクセス
2. 「New OAuth App」をクリック
3. 以下を入力して「Register application」

| 項目 | 値 |
|------|----|
| Application name | （任意：アプリ名） |
| Homepage URL | `http://localhost:8000` |
| Authorization callback URL | `http://localhost:8000/auth/github/callback` |

4. 「Generate a new client secret」でシークレットを生成
5. **Client ID** と **Client Secret** を控えておく

### 3. .env に認証情報を追記する

> ⚠️ **注意**：`.env` ファイルは Git にコミットしないでください（`.gitignore` に含まれています）。
> Client ID / Client Secret は絶対に公開しないでください。

```dotenv
GITHUB_CLIENT_ID=取得したClient_IDを記載
GITHUB_CLIENT_SECRET=取得したClient_Secretを記載
GITHUB_REDIRECT_URI=http://localhost:8000/auth/github/callback
```

### 4. config/services.php に追記する

`config/services.php` の配列に以下を追加します：

```php
'github' => [
    'client_id'     => env('GITHUB_CLIENT_ID'),
    'client_secret' => env('GITHUB_CLIENT_SECRET'),
    'redirect'      => env('GITHUB_REDIRECT_URI'),
],
```

> `env()` を使って `.env` の値を参照します。コード上に直接キーを書かないのがベストプラクティスです。

### 5. SocialiteController を作成する

```bash
php artisan make:controller Auth/SocialiteController
```

`app/Http/Controllers/Auth/SocialiteController.php` を以下で実装します：

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    /**
     * OAuth プロバイダの認証ページへリダイレクトする
     */
    public function redirect(string $provider)
    {
        return Socialite::driver($provider)->redirect();
    }

    /**
     * OAuth プロバイダからのコールバックを処理する
     */
    public function callback(string $provider)
    {
        // プロバイダからユーザー情報を取得
        $socialUser = Socialite::driver($provider)->user();

        // 既存ユーザーを検索し、なければ新規作成（updateOrCreate パターン）
        $user = User::updateOrCreate(
            // 検索条件：provider + provider_id の組み合わせで一意に特定
            [
                'provider'    => $provider,
                'provider_id' => $socialUser->getId(),
            ],
            // 作成・更新するデータ
            [
                'name'              => $socialUser->getName() ?? $socialUser->getNickname(),
                'email'             => $socialUser->getEmail(),
                'avatar'            => $socialUser->getAvatar(),
                'email_verified_at' => now(), // OAuth 経由はメール確認済みとして扱う
            ]
        );

        Auth::login($user);

        return redirect()->intended('/dashboard');
    }
}
```

**`updateOrCreate` の仕組み：**
- 第1引数の条件でレコードを検索する
- 見つかれば第2引数の内容で更新する
- 見つからなければ第1引数 + 第2引数をマージして新規作成する

### 6. ルートを追加する

`routes/web.php` に以下を追記します：

```php
use App\Http\Controllers\Auth\SocialiteController;

Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirect'])
    ->name('socialite.redirect');

Route::get('/auth/{provider}/callback', [SocialiteController::class, 'callback'])
    ->name('socialite.callback');
```

`{provider}` は動的セグメントです。`github` や `google` など文字列が入ります。

### 7. ログイン画面に GitHub ボタンを追加する

`resources/js/Pages/Auth/Login.vue` の `</form>` の直後に追記します：

```vue
<!-- OAuth ログイン -->
<div class="mt-4">
    <!-- 区切り線 -->
    <div class="relative">
        <div class="absolute inset-0 flex items-center">
            <span class="w-full border-t border-gray-300" />
        </div>
        <div class="relative flex justify-center text-sm">
            <span class="bg-white px-2 text-gray-500">または</span>
        </div>
    </div>

    <!-- GitHub ログインボタン -->
    <a
        :href="route('socialite.redirect', { provider: 'github' })"
        class="mt-4 flex w-full items-center justify-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
    >
        <!-- GitHub アイコン（SVG） -->
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"/>
        </svg>
        GitHub でログイン
    </a>
</div>
```

---

## PART 4 — メール認証の設定

### 仕組みの確認

`User` モデルに `implements MustVerifyEmail` が付いていると、
登録後に自動でメール認証フローが走ります。

### ローカル環境での確認方法

開発中はメールを実際に送信せず、ログに出力する設定が便利です。

`.env` の設定：

```dotenv
MAIL_MAILER=log
```

認証メールの内容は以下のコマンドで確認できます：

```bash
tail -f storage/logs/laravel.log
```

出力されたリンクをブラウザで開くと認証が完了します。

---

## 動作確認

### 1. 開発サーバーを起動する

```bash
# ターミナル1
php artisan serve

# ターミナル2
npm run dev
```

### 2. GitHub OAuth の動作確認

```
http://localhost:8000/login
```

「GitHub でログイン」ボタンをクリック → GitHub の認証画面に遷移 → 承認 → `/dashboard` にリダイレクト

### 3. DB に保存されているか確認する

```bash
php artisan tinker
>>> App\Models\User::latest()->first()
```

`provider` に `"github"`、`provider_id` に GitHub のユーザーIDが入っていれば成功です。

---

## ファイル変更サマリー

| ファイル | 変更内容 |
|---------|---------|
| `database/migrations/xxxx_add_oauth_columns_to_users_table.php` | 新規作成：avatar / provider / provider_id カラム追加 |
| `app/Models/User.php` | `$fillable` に3カラム追加 |
| `app/Http/Controllers/Auth/SocialiteController.php` | 新規作成：OAuth リダイレクト・コールバック処理 |
| `config/services.php` | GitHub OAuth 設定を追加 |
| `routes/web.php` | Socialite ルート2本を追加 |
| `resources/js/Pages/Auth/Login.vue` | GitHub ログインボタンを追加 |
| `.env` | GitHub Client ID / Secret を追加（※ Git 管理外） |

---

## よくあるエラーと対処法

| エラー | 原因 | 対処 |
|--------|------|------|
| `InvalidStateException` | コールバック URL が一致しない | GitHub OAuth アプリの Callback URL と `.env` の `GITHUB_REDIRECT_URI` を一致させる |
| `Column not found: avatar` | マイグレーション未実行 | `php artisan migrate` を実行する |
| `Call to undefined method change()` | `doctrine/dbal` 未インストール | `composer require doctrine/dbal` を実行する |
| ログイン後に `verify-email` に飛ばされる | `MustVerifyEmail` が有効 | OAuth ログイン時に `email_verified_at` を `now()` でセットしているか確認する |

---

## 学習ポイントまとめ

- **`MustVerifyEmail`** — インターフェースを実装するだけでメール認証フローが有効になる
- **`updateOrCreate`** — 検索して「あれば更新、なければ作成」する Eloquent の便利メソッド
- **`env()` 関数** — 機密情報は `.env` に書いてコードに直接埋め込まない
- **動的ルートセグメント** — `{provider}` で `github` / `google` を1本のルートで扱える
- **`nullable()->change()`** — 既存カラムの属性変更には `doctrine/dbal` が必要
