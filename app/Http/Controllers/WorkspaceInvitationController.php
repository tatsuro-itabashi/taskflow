<?php

namespace App\Http\Controllers;

use App\Jobs\SendWorkspaceInvitationJob;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkspaceInvitationController extends Controller
{
    /**
     * 招待を送信する
     */
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'role'  => ['required', 'in:admin,member'],
        ]);

        // すでに招待済みかチェック
        $exists = $workspace->invitations()
            ->where('email', $request->email)
            ->where('expires_at', '>', now())
            ->exists();

        if ($exists) {
            return back()->withErrors(['email' => 'すでに招待済みです']);
        }

        // 招待レコードを作成
        $invitation = $workspace->invitations()->create([
            'email'      => $request->email,
            'role'       => $request->role,
            'token'      => Str::random(32),
            'expires_at' => now()->addDays(7),
        ]);

        // Job をキューに積む（非同期でメール送信）
        SendWorkspaceInvitationJob::dispatch($invitation);

        return back()->with('success', "{$request->email} に招待メールを送信しました");
    }
}
