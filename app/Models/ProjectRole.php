<?php

namespace App\Models;

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
}
