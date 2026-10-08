<?php

namespace App\Models\Hrms\Attendance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Attendance/HRMS — a named working-hours definition.
 *
 * Times are time-of-day, not timestamps: a shift is “09:00–18:00”, and a
 * datetime would pretend a date is part of the definition. Overnight shifts
 * read start > end with `is_night` set — the computation wraps those, not
 * the schema.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $start_time
 * @property string $end_time
 * @property int $break_minutes
 * @property int $grace_minutes
 * @property float|null $min_hours
 * @property bool $is_night
 * @property bool $is_active
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AttendanceRoster> $rosters
 */
class AttendanceShift extends Model
{
    protected $table = 'attendance_shifts';

    protected $fillable = [
        'name',
        'code',
        'start_time',
        'end_time',
        'break_minutes',
        'grace_minutes',
        'min_hours',
        'is_night',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer',
            'grace_minutes' => 'integer',
            'min_hours' => 'decimal:2',
            'is_night' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function rosters(): HasMany
    {
        return $this->hasMany(AttendanceRoster::class, 'shift_id');
    }

    /** @param Builder<AttendanceShift> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
