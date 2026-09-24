<?php

namespace App\Events;

use App\Models\Comment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommentSynced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Comment $comment,
        public readonly string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('project.'.$this->comment->task->project_id)];
    }

    public function broadcastAs(): string
    {
        return 'comment.synced';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'comment_id' => $this->comment->id,
            'task_id' => $this->comment->task_id,
            'project_id' => $this->comment->task->project_id,
            'user_id' => $this->comment->user_id,
        ];
    }
}
