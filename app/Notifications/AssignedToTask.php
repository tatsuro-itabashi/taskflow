<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssignedToTask extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly Task $task,
        public readonly User $assignedBy
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // ['database'] だけにすればメールなしの DB 通知のみ、['mail'] だけにすればメールのみ送信される
        return ['database', 'mail'];
    }

    /**
     * database チャンネル：notifications テーブルに保存する内容
     *
     * @param object $notifiable
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'task_id'       => $this->task->id,
            'task_title'    => $this->task->title,
            'project_id'    => $this->task->project_id,
            'assigned_by'   => [
                'id'   => $this->assignedBy->id,
                'name' => $this->assignedBy->name,
            ],
        ];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = url("/workspaces/{$this->task->project->workspace_id}/projects/{$this->task->project_id}");

        return (new MailMessage)
            ->subject("タスク「{$this->task->title}」が割り当てられました")
            ->greeting("こんにちは、{$notifiable->name} さん")
            ->line("{$this->assignedBy->name} さんがタスクを割り当てました。")
            ->line("タスク名：**{$this->task->title}**")
            ->action('タスクを確認する', $url)
            ->line('ご確認よろしくお願いします。');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
