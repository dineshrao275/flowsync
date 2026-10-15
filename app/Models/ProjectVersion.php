<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectVersion extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'description',
        'release_date',
        'released',
        'archived',
    ];

    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'released' => 'boolean',
            'archived' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'version_id');
    }
}
