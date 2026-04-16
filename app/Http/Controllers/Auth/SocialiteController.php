<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    /**
     * GitHub の OAuth 認証ページへリダイレクト
     */
    public function redirect(string $provider)
    {
        return Socialite::driver($provider)->redirect();
    }

    /**
     * GitHub からのコールバック処理
     */
    public function callback(string $provider)
    {
        $socialUser = Socialite::driver($provider)->user();

        // 既存ユーザーの検索、なければ新規作成
        $user = User::updateOrCreate(
            [
                'provider'    => $provider,
                'provider_id' => $socialUser->getId(),
            ],
            [
                'name'              => $socialUser->getName() ?? $socialUser->getNickname(),
                'email'             => $socialUser->getEmail(),
                'avatar'            => $socialUser->getAvatar(),
                'email_verified_at' => now(), // OAuth ユーザーはメール確認済み扱い
            ]
        );

        Auth::login($user);

        return redirect()->intended('/dashboard');
    }
}
