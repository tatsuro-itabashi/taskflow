<?php

namespace App\Mail;

use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkspaceInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * ShouldQueue を implements することで mail()->send() でなく
     * mail()->queue() と同じ動作になる
     */
    public function __construct(
        public readonly WorkspaceInvitation $invitation,
        public readonly Workspace $workspace,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "「{$this->workspace->name}」への招待",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.workspace.invitation',
            with: [
                'inviteUrl'     => url("/invitations/{$this->invitation->token}/accept"),
                'workspaceName' => $this->workspace->name,
                'expiresAt'     => $this->invitation->expires_at->format('Y年m月d日'),
            ],
        );
    }
}
