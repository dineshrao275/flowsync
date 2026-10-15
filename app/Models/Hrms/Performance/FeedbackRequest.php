<?php

namespace App\Models\Hrms\Performance;

use App\Enums\Hrms\FeedbackRelation;
use App\Enums\Hrms\FeedbackRequestStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Performance/HRMS — one asked-for perspective on one reviewee.
 *
 * `from` is the reviewer, `to` the reviewee — the direction matters
 * because anonymity is read off `relation` at display time (peer rows
 * aggregate, manager rows attribute). Responses hang off the request so
 * declining still leaves the ask visible as asked-and-declined.
 */
class FeedbackRequest extends Model
{
    protected $table = 'feedback_requests';

    protected $fillable = [
        'cycle_id',
        'from_employee_id',
        'to_employee_id',
        'relation',
        'status',
        'due_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cycle_id' => 'integer',
            'from_employee_id' => 'integer',
            'to_employee_id' => 'integer',
            'relation' => FeedbackRelation::class,
            'status' => FeedbackRequestStatus::class,
            'due_date' => 'date',
            'created_by' => 'integer',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'cycle_id');
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    /** @return HasMany<FeedbackResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(FeedbackResponse::class, 'feedback_request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
