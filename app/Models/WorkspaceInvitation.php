<?php

namespace App\Models;

use Database\Factories\WorkspaceInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceInvitation extends Model
{
    /** @use HasFactory<WorkspaceInvitationFactory> */
    use HasFactory;

    protected $fillable = ['workspace_id', 'email', 'token', 'role', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** 有効期限切れかどうか */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
