<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'workspace_id',
        'created_by',
        'lead_user_id',
        'name',
        'key',
        'description',
        'icon',
        'start_date',
        'due_date',
        'last_task_sequence',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'archived_at' => 'datetime',
            'last_task_sequence' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot('project_role_id', 'added_by')
            ->withTimestamps();
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(TaskStatus::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function memberRole(User $user): ?ProjectRole
    {
        $roleId = null;

        if ($this->relationLoaded('members')) {
            $roleId = $this->members->first(
                fn ($member) => $member->id === $user->id
            )?->pivot?->project_role_id;
        } else {
            $roleId = $this->members()
                ->wherePivot('user_id', $user->id)
                ->first()?->pivot->project_role_id;
        }

        return $roleId ? ProjectRole::find($roleId) : null;
    }

    public function isMember(User $user): bool
    {
        return $this->members()->wherePivot('user_id', $user->id)->exists();
    }
}
