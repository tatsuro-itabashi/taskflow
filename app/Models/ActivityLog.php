<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'subject_type',
        'subject_id',
        'action',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array', // JSON を自動で配列に変換
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ログの説明文を生成する */
    public function getDescriptionAttribute(): string
    {
        $subject = $this->subject_type;
        $actions = [
            'created' => "新しい {$subject} を作成しました",
            'updated' => "{$subject} を更新しました",
            'deleted' => "{$subject} を削除しました",
        ];

        return $actions[$this->action] ?? "{$subject} を操作しました";
    }
}
