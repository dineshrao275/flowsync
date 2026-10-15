<?php

namespace App\Enums\Hrms;

enum CheckInMood: string
{
    case Great = 'great';
    case Good = 'good';
    case Ok = 'ok';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::Great => 'Great',
            self::Good => 'Good',
            self::Ok => 'Ok',
            self::Low => 'Low',
        };
    }
}
