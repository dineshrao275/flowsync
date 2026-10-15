<?php

namespace App\Enums\Hrms;

enum AttendanceDayStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case HalfDay = 'half_day';
    case Late = 'late';
    case Leave = 'leave';
    case Holiday = 'holiday';
    case WeekOff = 'week_off';
    case Remote = 'remote';
    case Inactive = 'inactive';

    /**
     * The computation writes only the attendance-owned four
     * (present/absent/half_day/late). Leave, holiday, week_off, remote and
     * inactive are merged in by `dayStatus()` from leave balances, the
     * holiday calendar, the roster and the work mode — a day the computation
     * marked `absent` becomes `leave` there, never here, so the raw
     * photograph and the merged reading stay distinguishable.
     */
    public function isAttendanceOwned(): bool
    {
        return in_array($this, [self::Present, self::Absent, self::HalfDay, self::Late], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Absent => 'Absent',
            self::HalfDay => 'Half day',
            self::Late => 'Late',
            self::Leave => 'Leave',
            self::Holiday => 'Holiday',
            self::WeekOff => 'Week off',
            self::Remote => 'Remote',
            self::Inactive => 'Inactive',
        };
    }
}
