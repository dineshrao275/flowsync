<?php

namespace App\Models\Hrms\Performance;

use App\Enums\Hrms\PerformanceCycleStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Performance/HRMS — one review period.
 *
 * The stage machine moves forward only (P12.3 owns the transitions); this
 * model names the window every goal's evidence is read against. `slug` is
 * server-allocated and unique.
 */
class PerformanceCycle extends Model
{
    protected $table = 'performance_cycles';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'period_start',
        'period_end',
        'stage',
        'anonymity',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'stage' => PerformanceCycleStage::class,
            'is_active' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    /** @return HasMany<PerformanceGoal, $this> */
    public function goals(): HasMany
    {
        return $this->hasMany(PerformanceGoal::class, 'cycle_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
