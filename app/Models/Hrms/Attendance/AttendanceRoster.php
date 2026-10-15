<?php

namespace App\Models\Hrms\Attendance;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Attendance/HRMS — one person on one shift over a date range.
 *
 * The authoritative shift source: when the day is computed, the roster
 * active on the work date wins over the employee’s `shift_id` default.
 * `weekly_offs` is seven Monday-first ints (1 = off) — JSON rather than
 * seven booleans because the editor moves whole patterns, not single days.
 *
 * @property int $id
 * @property int $employee_id
 * @property int|null $shift_id
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property array<int>|null $weekly_offs
 * @property bool $is_flexible
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read AttendanceShift|null $shift
 */
class AttendanceRoster extends Model
{
    protected $table = 'attendance_rosters';

    protected $fillable = [
        'employee_id',
        'shift_id',
        'effective_from',
        'effective_to',
        'weekly_offs',
        'is_flexible',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'shift_id' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'weekly_offs' => 'array',
            'is_flexible' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(AttendanceShift::class, 'shift_id');
    }

    /**
     * The roster covering a person on a date: started on or before it, and
     * either open-ended or not yet ended. Overlapping ranges are refused by
     * the unique index, so at most one row answers.
     *
     * @param  Builder<AttendanceRoster>  $query
     */
    public function scopeActiveOn(Builder $query, string $date): void
    {
        // whereDate throughout: same SQLite typeless-column trap as the
        // day lookup — an exact-string match misses rows the `date` cast
        // stored with a time part.
        $query->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $nested) => $nested->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
