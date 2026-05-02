<?php

use App\Http\Controllers\Api\NotificationController;
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

// v1 グループ（バージョニング）
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    // プロジェクト配下のタスク CRUD
    Route::apiResource('projects.tasks', TaskController::class);

    // 通知
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
});

// トークン発行
Route::post('/tokens/create', function (Request $request) {
    $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    $user = User::query()->where('email', $request->email)->first();

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
