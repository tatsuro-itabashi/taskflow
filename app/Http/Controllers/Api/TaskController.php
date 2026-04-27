<?php

namespace App\Http\Controllers\Api;

use App\Events\TaskCreated;
use App\Events\TaskDeleted;
use App\Events\TaskUpdated;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController extends Controller
{
    /**
     * タスク一覧を返す
     *
     * @response AnonymousResourceCollection<TaskResource>
     */
    public function index(Project $project): AnonymousResourceCollection
    {
        $tasks = $project->tasks()
            ->with('assignee')
            ->withCount('attachments')
            ->orderBy('position')
            ->get();

        return TaskResource::collection($tasks);
    }

    /**
     * タスクを作成する
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status'      => ['nullable', 'in:todo,in_progress,in_review,done'],
            'priority'    => ['nullable', 'in:low,medium,high,urgent'],
            'due_date'    => ['nullable', 'date'],
            'assignee_id' => ['nullable', 'exists:users,id'],
        ]);

        $task = $project->tasks()->create([
            ...$validated,
            'created_by' => $request->user()->id,
            'position'   => $project->tasks()->max('position') + 1,
        ]);

        // イベントを発火（Listener が自動で動く）
        TaskCreated::dispatch($task, $request->user());

        return (new TaskResource($task->load('assignee')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * タスク詳細を返す
     */
    public function show(Project $project, Task $task): TaskResource
    {
        return new TaskResource(
            $task->load(['assignee', 'attachments'])
        );
    }

    /**
     * タスクを更新する
     */
    public function update(Request $request, Project $project, Task $task): TaskResource
    {
        $validated = $request->validate([
            'title'       => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status'      => ['sometimes', 'in:todo,in_progress,in_review,done'],
            'priority'    => ['sometimes', 'in:low,medium,high,urgent'],
            'due_date'    => ['nullable', 'date'],
            'assignee_id' => ['nullable', 'exists:users,id'],
            'position'    => ['sometimes', 'integer', 'min:0'],
        ]);

        $task->update($validated);

        // 変更前の値を記録
        $oldValues = $task->only(array_keys($validated));
        $task->update($validated);
        $newValues = $task->fresh()->only(array_keys($validated));

        // 実際に変わったフィールドだけ記録
        $changes = [];
        foreach ($newValues as $key => $new) {
            if ($oldValues[$key] != $new) {
                $changes['old'][$key] = $oldValues[$key];
                $changes['new'][$key] = $new;
            }
        }

        TaskUpdated::dispatch($task->fresh(), $request->user(), $changes);

        return new TaskResource($task->fresh()->load('assignee'));
    }

    /**
     * タスクを削除する
     */
    public function destroy(Project $project, Task $task): JsonResponse
    {
        $task->delete();

        $deletedTask = clone $task; // 削除前に情報を保持
        $task->delete();

        TaskDeleted::dispatch($deletedTask, auth()->user());

        return response()->json(['message' => 'タスクを削除しました']);
    }
}
