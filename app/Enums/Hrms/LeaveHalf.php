<?php

namespace App\Enums\Hrms;

enum LeaveHalf: string
{
    case Full = 'full';
    case FirstHalf = 'first_half';
    case SecondHalf = 'second_half';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full day',
            self::FirstHalf => 'First half',
            self::SecondHalf => 'Second half',
        };
    }

    /**
     * The day fraction an endpoint contributes: a half on either end counts
     * half, a full day counts one.
     */
    public function days(): float
    {
        return $this === self::Full ? 1.0 : 0.5;
    }
}
