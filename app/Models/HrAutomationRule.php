<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrAutomationRule extends Model
{
    protected $fillable = ['name', 'trigger', 'project_id', 'tasks', 'is_active', 'created_by', 'run_count', 'last_run_at'];

    protected function casts(): array
    {
        return ['tasks' => 'array', 'is_active' => 'boolean', 'last_run_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
