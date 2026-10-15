<?php

namespace App\Enums\Hrms;

enum DocumentRequestSource: string
{
    case Onboarding = 'onboarding';
    case Offboarding = 'offboarding';
    case Hr = 'hr';

    public function label(): string
    {
        return match ($this) {
            self::Onboarding => 'Onboarding',
            self::Offboarding => 'Offboarding',
            self::Hr => 'HR',
        };
    }
}
