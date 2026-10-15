<?php

namespace App\Models\Hrms\Performance;

use App\Enums\Hrms\GoalMetricType;
use App\Enums\Hrms\GoalProgressSource;
use App\Enums\Hrms\GoalStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Performance/HRMS — one person's goal for one cycle.
 *
 * Progress is evidence-driven (`progress_evidence` snapshots the counts)
 * or hand-set for `manual` goals; status is a human decision the evidence
 * refresh never touches, and no rating is ever written here — automated
 * signals inform reviewers, they do not rate.
 */
class PerformanceGoal extends Model
{
    protected $table = 'performance_goals';

    protected $fillable = [
        'cycle_id',
        'employee_id',
        'title',
        'description',
        'category',
        'metric_type',
        'target_value',
        'weight',
        'due_date',
        'status',
        'progress_percent',
        'progress_source',
        'progress_evidence',
        'achieved_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cycle_id' => 'integer',
            'employee_id' => 'integer',
            'metric_type' => GoalMetricType::class,
            'target_value' => 'decimal:2',
            'weight' => 'decimal:2',
            'due_date' => 'date',
            'status' => GoalStatus::class,
            'progress_percent' => 'decimal:2',
            'progress_source' => GoalProgressSource::class,
            'progress_evidence' => 'array',
            'achieved_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<GoalTaskLink, $this> */
    public function taskLinks(): HasMany
    {
        return $this->hasMany(GoalTaskLink::class, 'goal_id');
    }

    /** @return BelongsToMany<Task, $this> */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(
            Task::class,
            'goal_task_links',
            'goal_id',
            'task_id',
        )->withPivot(['created_by'])->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
