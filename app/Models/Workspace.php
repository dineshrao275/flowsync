<?php

namespace App\Models;

use App\Enums\WorkspaceMemberRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $fillable = [
        'created_by',
        'name',
        'slug',
        'description',
        'icon',
        'timezone',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role', 'added_by')
            ->withTimestamps();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function labels(): HasMany
    {
        return $this->hasMany(Label::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function memberRole(User $user): ?WorkspaceMemberRole
    {
        $role = null;

        if ($this->relationLoaded('members')) {
            $role = $this->members->first(
                fn ($member) => $member->id === $user->id
            )?->pivot?->role;
        } else {
            $role = $this->members()
                ->wherePivot('user_id', $user->id)
                ->first()?->pivot->role;
        }

        return $role ? WorkspaceMemberRole::from($role) : null;
    }

    public function isMember(User $user): bool
    {
        return $this->memberRole($user) !== null;
    }
}
