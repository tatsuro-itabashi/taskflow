<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AvatarController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        // バリデーション
        $request->validate([
            'avatar' => [
                'required',
                'image',                    // jpeg, png, gif, webp のみ
                'mimes:jpeg,png,webp',      // 拡張子を限定
                'max:2048',                 // 2MB 以内
            ],
        ]);

        $user = $request->user();

        // 古いアバターを削除（ストレージを無駄に使わない）
        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        // ファイルを保存（自動でユニークなファイル名を生成）
        // 保存先: storage/app/public/avatars/ランダム文字列.jpg
        $path = $request->file('avatar')->store('avatars', 'public');

        // DB を更新
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
