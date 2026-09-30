<?php

namespace App\Enums\Hrms;

enum ReviewVisibility: string
{
    case Hidden = 'hidden';
    case Shared = 'shared';

    public function label(): string
    {
        return match ($this) {
            self::Hidden => 'Hidden',
            self::Shared => 'Shared',
        };
    }
}
