<?php

namespace App\Models;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\TaskLink\TaskLink;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'project_id',
        'created_by',
        'reporter_id',
        'assignee_id',
        'parent_id',
        'epic_id',
        'status_id',
        'priority_id',
        'key',
        'sequence',
        'title',
        'description',
        'due_date',
        'start_date',
        'story_points',
        'sprint_id',
        'issue_type_id',
        'version_id',
        'estimate_minutes',
        'position',
        'completed_at',
        'archived_at',
        'hrms_employee_id',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'start_date' => 'date',
            'story_points' => 'float',
            'sprint_id' => 'integer',
            'issue_type_id' => 'integer',
            'epic_id' => 'integer',
            'version_id' => 'integer',
            'estimate_minutes' => 'integer',
            'position' => 'float',
            'sequence' => 'integer',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
            'hrms_employee_id' => 'integer',
        ];
    }

    public function issueType(): BelongsTo
    {
        return $this->belongsTo(IssueType::class, 'issue_type_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProjectVersion::class, 'version_id');
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(ProjectComponent::class, 'task_component', 'task_id', 'component_id');
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_watchers', 'task_id', 'user_id');
    }

    public function isWatchedBy(User $user): bool
    {
        return $this->watchers()->where('users.id', $user->id)->exists();
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

    /**
     * The HR-owned person this task was filed against (P20.1): an HR
     * admin's ask living on the employee's HR home — not the assignee,
     * which is the project member doing the work.
     */
    public function hrmsEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hrms_employee_id');
    }

    /**
     * The evidence bridges naming this task (P20.1): which employee
     * records count it, and for what kind of claim.
     */
    public function taskLinks(): HasMany
    {
        return $this->hasMany(TaskLink::class, 'task_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'epic_id');
    }

    /** Issues linked to this one as their epic (or initiative). */
    public function epicChildren(): HasMany
    {
        return $this->hasMany(Task::class, 'epic_id');
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
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

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class, 'task_id')->ordered();
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
