<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IssueType extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'is_subtask',
        'hierarchy_level',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_subtask' => 'boolean',
            'hierarchy_level' => 'integer',
            'position' => 'integer',
        ];
    }

    /** 0 initiative, 1 epic, 2 standard, 3 sub-task; a sub-task type is always level 3. */
    public function level(): int
    {
        return $this->is_subtask ? 3 : (int) ($this->hierarchy_level ?? 2);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'issue_type_id');
    }
}
