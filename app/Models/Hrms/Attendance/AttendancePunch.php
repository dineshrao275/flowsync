<?php

namespace App\Models\Hrms\Attendance;

use App\Enums\Hrms\PunchDirection;
use App\Enums\Hrms\PunchSource;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Org\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Attendance/HRMS — one raw clock event.
 *
 * Raw, always: punches are never edited, only superseded (by a regularization
 * punch with source `regularized`). The day row is the photograph; this
 * table is the negative it was developed from, and rewriting the negative
 * would make every derived day unverifiable.
 *
 * `is_out_of_range` is a flag, never a block: a mispinned geofence must not
 * lock a person out of recording that they worked.
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $punch_at
 * @property PunchDirection $direction
 * @property PunchSource $source
 * @property float|null $lat
 * @property float|null $lng
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $device_id
 * @property int|null $location_id
 * @property bool $is_out_of_range
 * @property string|null $out_of_range_reason
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 */
class AttendancePunch extends Model
{
    protected $table = 'attendance_punches';

    protected $fillable = [
        'employee_id',
        'punch_at',
        'direction',
        'source',
        'lat',
        'lng',
        'ip',
        'user_agent',
        'device_id',
        'location_id',
        'is_out_of_range',
        'out_of_range_reason',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'punch_at' => 'datetime',
            'direction' => PunchDirection::class,
            'source' => PunchSource::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'location_id' => 'integer',
            'is_out_of_range' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<AttendancePunch> $query */
    public function scopeForEmployee(Builder $query, int $employeeId): void
    {
        $query->where('employee_id', $employeeId);
    }
}
