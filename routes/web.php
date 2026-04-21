<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\AttachmentController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::patch('/avatar', [AvatarController::class, 'update'])->name('avatar.update');
    Route::delete('/avatar', [AvatarController::class, 'destroy'])->name('avatar.destroy');

    Route::post('/tasks/{task}/attachments', [AttachmentController::class, 'store'])
    ->name('attachments.store');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])
    ->name('attachments.destroy');
});

Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirect'])
    ->name('socialite.redirect');

Route::get('/auth/{provider}/callback', [SocialiteController::class, 'callback'])
    ->name('socialite.callback');

Route::middleware(['auth', 'verified'])->group(function () {

    // ワークスペース配下のプロジェクト（ネストしたリソースルート）
    Route::resource('workspaces.projects', ProjectController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->shallow();  // 詳細・編集は /projects/{id} で直接アクセス可能にする
});

require __DIR__.'/auth.php';
