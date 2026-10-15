<?php

namespace App\Models\Hrms\Performance;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Performance/HRMS — one reviewer's submitted perspective.
 *
 * One row per (request, reviewer): the unique pair is the ballot box —
 * resubmitting edits the row rather than stuffing a second ballot, and
 * the request keeps the only authoritative submitted/declined state.
 */
class FeedbackResponse extends Model
{
    protected $table = 'feedback_responses';

    protected $fillable = [
        'feedback_request_id',
        'from_employee_id',
        'rating',
        'body',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'feedback_request_id' => 'integer',
            'from_employee_id' => 'integer',
            'rating' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FeedbackRequest::class, 'feedback_request_id');
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }
}
