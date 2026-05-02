<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

class ProjectPolicy
{
    /**
     * ワークスペースのメンバーロールを取得するヘルパー
     */
    private function getRole(User $user, Workspace $workspace): ?string
    {
        // workspace の owner は常に 'owner' として扱う
        // （workspace_user に登録されていない場合もあるため）
        if ($workspace->owner_id === $user->id) {
            return 'owner';
        }

        return $workspace->members()
            ->where('user_id', $user->id)
            ->value('workspace_user.role'); // pivot のカラムを直接取得
    }

    /**
     * プロジェクト一覧を見られるか（メンバーなら全員OK）
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $this->getRole($user, $workspace) !== null;
    }

    /**
     * プロジェクトを閲覧できるか（ワークスペースのメンバーなら全員OK）
     */
    public function view(User $user, Project $project): bool
    {
        return $this->getRole($user, $project->workspace) !== null;
    }

    /**
     * プロジェクトを作成できるか（owner / admin のみ）
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return in_array($this->getRole($user, $workspace), ['owner', 'admin']);
    }

    /**
     * プロジェクトを更新できるか（owner / admin のみ）
     */
    public function update(User $user, Project $project): bool
    {
        return in_array(
            $this->getRole($user, $project->workspace),
            ['owner', 'admin']
        );
    }

    /**
     * プロジェクトを削除できるか（owner のみ）
     */
    public function delete(User $user, Project $project): bool
    {
        return $this->getRole($user, $project->workspace) === 'owner';
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        return false;
    }
}
