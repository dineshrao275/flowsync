<?php

namespace App\Models\Hrms\Attendance;

use App\Enums\Hrms\RegularizationStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Attendance/HRMS — a request to correct one past day.
 *
 * The ask, not the correction: approval rewrites nothing in place. On
 * approval the service inserts `regularized`-source punches and recomputes
 * the day, so the day row always equals its inputs (the punch-table
 * docblock states the rule). `requested_first_in_at` is the corrected
 * clock-in, `requested_punch_at` the corrected clock-out — at least one is
 * required, and both must fall on `work_date`.
 *
 * @property int $id
 * @property int $attendance_day_id
 * @property int $employee_id
 * @property Carbon $work_date
 * @property Carbon|null $requested_punch_at
 * @property Carbon|null $requested_first_in_at
 * @property string $reason
 * @property RegularizationStatus $status
 * @property int|null $approval_id
 * @property Carbon|null $decided_at
 * @property int|null $decided_by_user_id
 * @property string|null $decision_note
 */
class AttendanceRegularizationRequest extends Model
{
    protected $table = 'attendance_regularization_requests';

    protected $fillable = [
        'attendance_day_id',
        'employee_id',
        'work_date',
        'requested_punch_at',
        'requested_first_in_at',
        'reason',
        'status',
        'approval_id',
        'decided_at',
        'decided_by_user_id',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'attendance_day_id' => 'integer',
            'employee_id' => 'integer',
            'work_date' => 'date',
            'requested_punch_at' => 'datetime',
            'requested_first_in_at' => 'datetime',
            'status' => RegularizationStatus::class,
            'approval_id' => 'integer',
            'decided_at' => 'datetime',
            'decided_by_user_id' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->status === RegularizationStatus::Pending;
    }
}
