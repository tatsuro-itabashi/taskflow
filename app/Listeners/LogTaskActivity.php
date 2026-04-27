<?php

namespace App\Listeners;

use App\Events\TaskCreated;
use App\Events\TaskDeleted;
use App\Events\TaskUpdated;
use App\Models\ActivityLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogTaskActivity
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(TaskCreated $event): void
    {
        //
    }

    /**
     * TaskCreated イベントを処理する
     */
    public function handleTaskCreated(TaskCreated $event): void
    {
        ActivityLog::create([
            'workspace_id' => $event->task->project->workspace_id,
            'user_id'      => $event->createdBy->id,
            'subject_type' => 'task',
            'subject_id'   => $event->task->id,
            'action'       => 'created',
            'changes'      => null,
        ]);
    }

    /**
     * TaskUpdated イベントを処理する
     */
    public function handleTaskUpdated(TaskUpdated $event): void
    {
        // 変更がない場合はログを記録しない
        if (empty($event->changes['old']) && empty($event->changes['new'])) {
            return;
        }

        ActivityLog::create([
            'workspace_id' => $event->task->project->workspace_id,
            'user_id'      => $event->updatedBy->id,
            'subject_type' => 'task',
            'subject_id'   => $event->task->id,
            'action'       => 'updated',
            'changes'      => $event->changes,
        ]);
    }

    /**
     * TaskDeleted イベントを処理する
     */
    public function handleTaskDeleted(TaskDeleted $event): void
    {
        ActivityLog::create([
            'workspace_id' => $event->task->project->workspace_id,
            'user_id'      => $event->deletedBy->id,
            'subject_type' => 'task',
            'subject_id'   => $event->task->id,
            'action'       => 'deleted',
            'changes'      => ['title' => $event->task->title],
        ]);
    }
}
