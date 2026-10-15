<?php

namespace App\Models\Hrms\Performance;

use App\Enums\Hrms\CheckInMood;
use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Performance/HRMS — one written check-in for a cycle.
 *
 * Append-only by design (no updated_at): a check-in is a dated note, and
 * editing history would let a later mood rewrite an earlier one. There is
 * deliberately no `updated_by` — what was written stands.
 */
class CheckIn extends Model
{
    protected $table = 'check_ins';

    public $timestamps = false;

    protected $fillable = [
        'cycle_id',
        'employee_id',
        'body',
        'mood',
        'blockers',
        'needs_support',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'cycle_id' => 'integer',
            'employee_id' => 'integer',
            'mood' => CheckInMood::class,
            'needs_support' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
