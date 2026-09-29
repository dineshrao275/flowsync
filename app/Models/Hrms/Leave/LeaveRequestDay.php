<?php

namespace App\Models\Hrms\Leave;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leave/HRMS — one calendar date inside a leave ask.
 *
 * Derived, never edited: the engine re-splits the range whenever the ask
 * changes, so these rows are a photograph of the range, not a second thing
 * to keep in sync. Flagged dates (week-off, holiday, half) stay in the
 * table for the record and out of `total_days`.
 */
class LeaveRequestDay extends Model
{
    protected $table = 'leave_request_days';

    public $timestamps = false;

    protected $fillable = [
        'leave_request_id',
        'date',
        'is_holiday',
        'is_week_off',
        'is_half_day',
    ];

    protected function casts(): array
    {
        return [
            'leave_request_id' => 'integer',
            'date' => 'date',
            'is_holiday' => 'boolean',
            'is_week_off' => 'boolean',
            'is_half_day' => 'boolean',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }
}
