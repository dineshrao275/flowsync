<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Timesheet extends Model
{
    public const OPEN = 'open';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'user_id', 'week_start', 'status', 'total_minutes', 'billable_minutes',
        'submitted_at', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'total_minutes' => 'integer',
            'billable_minutes' => 'integer',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A submitted or approved sheet freezes the owner's logs for that week. */
    public function locksLogs(): bool
    {
        return in_array($this->status, [self::SUBMITTED, self::APPROVED], true);
    }
}
