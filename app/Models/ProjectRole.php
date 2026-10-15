<?php

namespace App\Models;

use App\Support\PermissionScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectRole extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_system',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'permissions' => 'array',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class, 'project_role_id');
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->permissions === ['*']) {
            return true;
        }

        return in_array($permission, $this->permissions ?? [], true);
    }

    /**
     * Scope-aware permission check, the project-role twin of User::granted().
     *
     * `hasPermission('tasks.view_own')` answers for a literal grant only; this
     * asks whether any grant the role holds SATISFIES the check — a wider
     * scope (`_all` answers a `_own` request), or the legacy unsuffixed slug
     * that has always meant "all". Non-scoped slugs resolve to themselves.
     */
    public function grants(string $permission): bool
    {
        foreach (PermissionScope::satisfying($permission) as $candidate) {
            if ($this->hasPermission($candidate)) {
                return true;
            }
        }

        return false;
    }
}
