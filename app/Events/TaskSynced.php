<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskSynced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('project.'.$this->task->project_id)];
    }

    public function broadcastAs(): string
    {
        return 'task.synced';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'task_id' => $this->task->id,
            'project_id' => $this->task->project_id,
            'status_id' => $this->task->status_id,
            'key' => $this->task->key,
        ];
    }
}
