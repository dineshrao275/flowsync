<?php

namespace App\Models\Hrms\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Attendance/HRMS — a repeating shift cycle ("6 on, 2 off").
 *
 * `cycle` is an ordered list of shift ids; null marks a day off. Applying a
 * rotation writes ordinary roster rows — see `RosterService::applyRotation()`.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property array<int, int|null> $cycle
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AttendanceRotation extends Model
{
    protected $table = 'attendance_rotations';

    protected $fillable = ['name', 'code', 'description', 'cycle', 'is_active'];

    protected function casts(): array
    {
        return [
            'cycle' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
