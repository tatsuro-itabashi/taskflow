<?php

use App\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;

// Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
//     return (int) $user->id === (int) $id;
// });

/**
 * workspace.{workspaceId} チャンネルの認可ルール
 * return true → 購読許可、false → 拒否（403）
 */
Broadcast::channel('workspace.{workspaceId}', function ($user, int $workspaceId) {
    $workspace = Workspace::query()->find($workspaceId);

    if (! $workspace) {
        return false;
    }

    // ワークスペースのメンバーなら購読を許可
    return $workspace->members()->where('user_id', $user->id)->exists()
        || $workspace->owner_id === $user->id;
});
