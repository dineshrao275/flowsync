<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskMoveRequest extends Model
{
    protected $fillable = ['task_id', 'project_id', 'from_status_id', 'to_status_id', 'position', 'requested_by', 'status', 'decided_by', 'decided_at', 'note'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'to_status_id');
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'from_status_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
