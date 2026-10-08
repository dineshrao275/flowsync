<?php

namespace App\Models\Hrms\Performance;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Performance/HRMS — one task evidencing one goal.
 *
 * A link, not a copy: the task stays the record, the goal reads it. (P12.6
 * co-designs the general employee<->task association with Phase 20; this
 * pivot answers only "what evidences this goal".)
 */
class GoalTaskLink extends Model
{
    protected $table = 'goal_task_links';

    protected $fillable = [
        'goal_id',
        'task_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'goal_id' => 'integer',
            'task_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(PerformanceGoal::class, 'goal_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
