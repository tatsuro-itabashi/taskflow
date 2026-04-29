<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly Task $task,
        public readonly User $changedBy,
        public readonly string $oldStatus,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     * どのチャンネルにブロードキャストするか
     * PrivateChannel = 認証済みユーザーのみ受信可能
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("workspace.{$this->task->project->workspace_id}"),
        ];
    }

    /**
     * フロントエンドに送るデータ
     */
    public function broadcastWith(): array
    {
        return [
            'task' => [
                'id'         => $this->task->id,
                'title'      => $this->task->title,
                'status'     => $this->task->status,
                'old_status' => $this->oldStatus,
            ],
            'changed_by' => [
                'id'   => $this->changedBy->id,
                'name' => $this->changedBy->name,
            ],
        ];
    }

    /**
     * フロントで受け取るイベント名（デフォルトは FQCN）
     */
    public function broadcastAs(): string
    {
        return 'task.status.changed';
    }
}
