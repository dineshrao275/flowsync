<?php

namespace App\Models\Hrms\Performance;

use App\Enums\Hrms\ReviewStatus;
use App\Enums\Hrms\ReviewVisibility;
use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Performance/HRMS — one person's review write-up for one cycle.
 *
 * Ratings are 1-5 per dimension, entered by humans — there is no overall
 * score column on purpose, and none may be added without revisiting the
 * phase's founding rule. `evidence_snapshot` freezes the goal counts the
 * reviewer saw, so a later refresh cannot rewrite what the rating was
 * based on. Visibility gates the employee's own read (P12.4 policy).
 */
class ReviewSummary extends Model
{
    protected $table = 'review_summaries';

    protected $fillable = [
        'cycle_id',
        'employee_id',
        'self_rating',
        'manager_rating',
        'strengths',
        'improvements',
        'manager_comments',
        'evidence_snapshot',
        'visibility_to_employee',
        'status',
        'submitted_at',
        'acknowledged_at',
        'manager_employee_id',
    ];

    protected function casts(): array
    {
        return [
            'cycle_id' => 'integer',
            'employee_id' => 'integer',
            'self_rating' => 'integer',
            'manager_rating' => 'integer',
            'evidence_snapshot' => 'array',
            'visibility_to_employee' => ReviewVisibility::class,
            'status' => ReviewStatus::class,
            'submitted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'manager_employee_id' => 'integer',
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

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }
}
