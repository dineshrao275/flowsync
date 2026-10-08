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
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_subtask' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'issue_type_id');
    }
}
