<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'status'      => $this->status,
            'priority'    => $this->priority,
            'due_date'    => $this->due_date?->format('Y-m-d'), // Carbon → 文字列
            'position'    => $this->position,

            // リレーション（ロード済みの場合のみ含める）
            'assignee' => $this->whenLoaded('assignee', fn() => [
                'id'         => $this->assignee->id,
                'name'       => $this->assignee->name,
                'avatar_url' => $this->assignee->avatar_url,
            ]),
            'attachments_count' => $this->whenCounted('attachments'),

            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
