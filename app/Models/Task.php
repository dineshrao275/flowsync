<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'project_id',
        'created_by',
        'reporter_id',
        'assignee_id',
        'parent_id',
        'status_id',
        'priority_id',
        'key',
        'sequence',
        'title',
        'description',
        'due_date',
        'estimate_minutes',
        'position',
        'completed_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'estimate_minutes' => 'integer',
            'position' => 'float',
            'sequence' => 'integer',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'status_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class, 'priority_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'task_label');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function workLogs(): HasMany
    {
        return $this->hasMany(WorkLog::class);
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'task_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'depends_on_task_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'subject_id')
            ->where('subject_type', static::class);
    }

    public function totalLoggedMinutes(): int
    {
        return (int) $this->workLogs()->sum('duration_minutes');
    }

    public function remainingMinutes(): ?int
    {
        if ($this->estimate_minutes === null) {
            return null;
        }

        return max(0, $this->estimate_minutes - $this->totalLoggedMinutes());
    }

    public function openBlockers(): HasMany
    {
        return $this->dependencies()
            ->where('type', 'blocks')
            ->whereHas('dependsOn', fn ($query) => $query->whereNull('completed_at'));
    }
}
