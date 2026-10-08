<?php

namespace App\Enums\Hrms;

enum OffboardingReason: string
{
    case Resigned = 'resigned';
    case Terminated = 'terminated';
    case Retired = 'retired';
    case ContractEnd = 'contract_end';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Resigned => 'Resigned',
            self::Terminated => 'Terminated',
            self::Retired => 'Retired',
            self::ContractEnd => 'Contract ended',
            self::Other => 'Other',
        };
    }
}
