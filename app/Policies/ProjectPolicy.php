<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{

    /**
     * ワークスペースのメンバーロールを取得するヘルパー
     * @param User $user
     * @param Workspace $workspace
     * @return string|null
     */
    private function getRole(User $user, Workspace $workspace): ?string
    {
        return $workspace->members()
            ->where('user_id', $user->id)
            ->value('workspace_user.role'); // pivot のカラムを直接取得
    }
    /**
     * プロジェクト一覧を見られるか（メンバーなら全員OK）
     * @param User $user
     * @param Workspace $workspace
     * @return bool
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $this->getRole($user, $workspace) !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * プロジェクトを作成できるか（owner / admin のみ）
     * @param User $user
     * @param Workspace $workspace
     * @return bool
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return in_array($this->getRole($user, $workspace), ['owner', 'admin']);
    }

    /**
     * プロジェクトを更新できるか（owner / admin のみ）
     * @param User $user
     * @param Project $project
     * @return bool
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
