<?php

namespace App\Models;

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Workspace extends Model
{
    /** @use HasFactory<\Database\Factories\WorkspaceFactory> */
    use HasFactory;

    // =============================
    // リレーション
    // =============================
    protected $fillable = ['owner_id', 'name', 'slug', 'color'];

    /** オーナーユーザー（多対1） */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** メンバー一覧（多対多）＋中間テーブルの role も取得 */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** 招待一覧（1対多） */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    // =============================
    // スコープ（絞り込み条件を再利用）
    // =============================

    /** 特定ユーザーが所属するワークスペースだけに絞る */
    public function scopeForUser($query, User $user)
    {
        return $query->whereHas('members', function($query) use ($user) {
            $query->where('user_id', $user->id);
        });
    }

    // =============================
    // アクセサ（値の加工）
    // =============================

    /** name をセットした際に slug を自動生成 */
    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            if (empty($workspace->slug)) {
                $workspace->slug = Str::slug($workspace->name) . '-' . Str::lower(Str::random(5));
            }
        });
    }
}
