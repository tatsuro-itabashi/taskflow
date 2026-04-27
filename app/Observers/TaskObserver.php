<?php

namespace App\Observers;

use App\Models\Task;
use Illuminate\Support\Facades\Log;

class TaskObserver
{
    /**
     * Handle the Task "created" event.
     */
    public function creating(Task $task): void
    {
        // position が未設定なら自動で末尾に配置
        if (! $task->position) {
            $task->position = Task::where('project_id', $task->project_id)
                ->max('position') + 1;
        }
    }

    /**
     * Handle the Task "created" event.
     */
    public function created(Task $task): void
    {
        Log::info("Task created: [{$task->id}] {$task->title}");
    }

    /**
     * Handle the Task "updated" event.
     */
    public function updated(Task $task): void
    {
        // status が done になった場合の処理などをここに書ける
    }

    /**
     * Handle the Task "deleted" event.
     */
    public function deleted(Task $task): void
    {
        Log::info("Task deleted: [{$task->id}] {$task->title}");
    }

    /**
     * Handle the Task "restored" event.
     */
    public function restored(Task $task): void
    {
        //
    }

    /**
     * Handle the Task "force deleted" event.
     */
    public function forceDeleted(Task $task): void
    {
        //
    }
}
