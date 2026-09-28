<?php

namespace App\Models\Hrms\Attendance;

use App\Enums\Hrms\AttendanceDayStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Attendance/HRMS — one person’s derived day.
 *
 * The photograph, recomputed idempotently from the punches: pairing in/out,
 * subtracting the break, deriving late/early/overtime from the settings
 * rounding rules. Never hand-edited — corrections arrive as regularization
 * punches and a recompute, so the row always equals its inputs.
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $work_date
 * @property int|null $shift_id
 * @property int|null $roster_id
 * @property Carbon|null $first_in_at
 * @property Carbon|null $last_out_at
 * @property int $worked_minutes
 * @property int $break_minutes
 * @property int $late_by_minutes
 * @property int $early_by_minutes
 * @property int $overtime_minutes
 * @property AttendanceDayStatus $status
 * @property bool $is_regularized
 * @property int|null $regularized_by_user_id
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 */
class AttendanceDay extends Model
{
    protected $table = 'attendance_days';

    protected $fillable = [
        'employee_id',
        'work_date',
        'shift_id',
        'roster_id',
        'first_in_at',
        'last_out_at',
        'worked_minutes',
        'break_minutes',
        'late_by_minutes',
        'early_by_minutes',
        'overtime_minutes',
        'status',
        'is_regularized',
        'regularized_by_user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'work_date' => 'date',
            'shift_id' => 'integer',
            'roster_id' => 'integer',
            'first_in_at' => 'datetime',
            'last_out_at' => 'datetime',
            'worked_minutes' => 'integer',
            'break_minutes' => 'integer',
            'late_by_minutes' => 'integer',
            'early_by_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'status' => AttendanceDayStatus::class,
            'is_regularized' => 'boolean',
            'regularized_by_user_id' => 'integer',
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

    public function roster(): BelongsTo
    {
        return $this->belongsTo(AttendanceRoster::class, 'roster_id');
    }

    public function regularizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'regularized_by_user_id');
    }
}
