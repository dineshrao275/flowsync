<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlaPolicy extends Model
{
    protected $fillable = ['project_id', 'name', 'priority_id', 'target_hours', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'target_hours' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
