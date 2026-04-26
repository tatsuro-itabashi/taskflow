<?php

namespace App\Jobs;

use App\Mail\WorkspaceInvitationMail;
use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendWorkspaceInvitationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * リトライ回数（失敗時に何回再試行するか）
     */
    public int $tries = 3;

    /**
     * タイムアウト秒数
     */
    public int $timeout = 30;

    public function __construct(
        public readonly WorkspaceInvitation $invitation,
    ) {}

    /**
     * Job の実際の処理
     */
    public function handle(): void
    {
        // 招待が有効期限内かチェック（処理前に再検証）
        if ($this->invitation->isExpired()) {
            return; // 期限切れなら何もしない
        }

        Mail::to($this->invitation->email)
            ->send(new WorkspaceInvitationMail(
                invitation: $this->invitation,
                workspace: $this->invitation->workspace,
            ));
    }

    /**
     * 失敗時の処理（ログに記録するなど）
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("招待メール送信失敗: {$this->invitation->email}", [
            'error' => $exception->getMessage(),
        ]);
    }
}
