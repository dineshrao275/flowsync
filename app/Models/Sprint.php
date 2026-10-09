<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sprint extends Model
{
    public const PLANNED = 'planned';

    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    protected $fillable = ['project_id', 'name', 'goal', 'status', 'start_date', 'end_date', 'started_at', 'completed_at', 'committed_points', 'completed_points', 'created_by'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'end_date' => 'date', 'started_at' => 'datetime', 'completed_at' => 'datetime',
            'committed_points' => 'float', 'completed_points' => 'float',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SprintTaskEvent::class);
    }
}
