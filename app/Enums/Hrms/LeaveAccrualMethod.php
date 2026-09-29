<?php

namespace App\Enums\Hrms;

enum LeaveAccrualMethod: string
{
    case None = 'none';
    case Annual = 'annual';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case PerPayroll = 'per_payroll';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No accrual',
            self::Annual => 'Annual',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::PerPayroll => 'Per payroll',
        };
    }

    /**
     * Whether `LeaveService::accrue()` credits this method on its own.
     * `per_payroll` is credited by the payroll run that owns the period, and
     * `none` is granted, never accrued — both skip the scheduled accrual.
     */
    public function isScheduled(): bool
    {
        return $this === self::Annual || $this === self::Monthly || $this === self::Quarterly;
    }
}
