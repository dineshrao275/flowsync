<?php

namespace App\Enums\Hrms;

/**
 * What a punch is for: ordinary work in/out, or the start/end of a break.
 * A break starts with an `out` and ends with an `in`, both of kind `break`.
 */
enum PunchKind: string
{
    case Work = 'work';
    case Break = 'break';

    public function label(): string
    {
        return match ($this) {
            self::Work => 'Work',
            self::Break => 'Break',
        };
    }
}
