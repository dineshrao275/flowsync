<?php

namespace App\Enums\Hrms;

enum OptionalHolidayStatus: string
{
    case Taken = 'taken';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Taken => 'Taken',
            self::Skipped => 'Skipped',
        };
    }
}
