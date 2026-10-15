<?php

namespace App\Enums\Hrms;

enum LeaveAccrualPeriod: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Biannual = 'biannual';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Biannual => 'Biannual',
            self::Annual => 'Annual',
        };
    }
}
