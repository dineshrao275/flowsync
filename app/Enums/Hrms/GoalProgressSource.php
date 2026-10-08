<?php

namespace App\Enums\Hrms;

enum GoalProgressSource: string
{
    case Auto = 'auto';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Automatic',
            self::Manual => 'Manual',
        };
    }
}
