<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends Model
{
    protected $fillable = ['project_id', 'name', 'is_active', 'trigger', 'conditions', 'actions', 'created_by', 'run_count', 'last_run_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'conditions' => 'array', 'actions' => 'array', 'last_run_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'rule_id');
    }
}
