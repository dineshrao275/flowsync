<?php

namespace App\Models;

use App\Enums\TaskStatusCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'slug',
        'category',
        'position',
        'color',
        'is_default',
        'is_done',
        'entry_rules',
    ];

    protected function casts(): array
    {
        return [
            'category' => TaskStatusCategory::class,
            'position' => 'integer',
            'is_default' => 'boolean',
            'is_done' => 'boolean',
            'entry_rules' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'status_id');
    }
}
