<?php

namespace App\Models\Hrms\Attendance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Attendance/HRMS — the networks a punch may come from.
 *
 * A mismatch flags the punch out-of-range; it never blocks it. An empty
 * active set means “no network policy”, not “deny everything” — a tenant
 * that never configured IP rules must not find clock-in broken on Monday.
 *
 * @property int $id
 * @property string $cidr
 * @property string|null $label
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AttendanceIpRule extends Model
{
    protected $table = 'attendance_ip_rules';

    protected $fillable = [
        'cidr',
        'label',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @param Builder<AttendanceIpRule> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
