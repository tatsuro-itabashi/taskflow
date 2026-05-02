<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        // ユーザーが所属するワークスペース一覧
        $workspaces = $user->workspaces()
            ->with('owner')
            ->withCount(['projects', 'members'])
            ->get();

        // ワークスペースごとの統計（5分キャッシュ）
        $stats = $workspaces->map(function (Workspace $workspace) {
            return Cache::remember(
                "workspace.{$workspace->id}.stats",
                300, // 5分
                function () use ($workspace) {
                    $projects = $workspace->projects()->withCount([
                        'tasks',
                        'tasks as todo_count' => fn ($q) => $q->where('status', 'todo'),
                        'tasks as in_progress_count' => fn ($q) => $q->where('status', 'in_progress'),
                        'tasks as done_count' => fn ($q) => $q->where('status', 'done'),
                    ])->get();

                    return [
                        'workspace_id' => $workspace->id,
                        'workspace_name' => $workspace->name,
                        'projects_count' => $projects->count(),
                        'total_tasks' => $projects->sum('tasks_count'),
                        'todo_count' => $projects->sum('todo_count'),
                        'in_progress_count' => $projects->sum('in_progress_count'),
                        'done_count' => $projects->sum('done_count'),
                    ];
                }
            );
        });

        // 最近のアクティビティ（ログイン中ユーザーのワークスペース全体）
        $workspaceIds = $workspaces->pluck('id');
        $recentActivity = Cache::remember(
            "user.{$user->id}.recent_activity",
            60, // 1分（アクティビティはリアルタイム性が高いので短め）
            function () use ($workspaceIds) {
                return ActivityLog::with('user')
                    ->whereIn('workspace_id', $workspaceIds)
                    ->latest()
                    ->take(10)
                    ->get()
                    ->map(fn ($log) => [
                        'id' => $log->id,
                        'description' => $log->description, // accessor
                        'user_name' => $log->user->name,
                        'action' => $log->action,
                        'created_at' => $log->created_at->diffForHumans(),
                    ]);
            }
        );

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentActivity' => $recentActivity,
        ]);
    }
}
