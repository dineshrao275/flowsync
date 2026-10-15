<?php

namespace App\Enums\Hrms;

enum LeaveAdjustmentKind: string
{
    case Opening = 'opening';
    case Accrual = 'accrual';
    case CarryForward = 'carry_forward';
    case Encashment = 'encashment';
    case Lapse = 'lapse';
    case Adjustment = 'adjustment';
    case Availed = 'availed';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening',
            self::Accrual => 'Accrual',
            self::CarryForward => 'Carry forward',
            self::Encashment => 'Encashment',
            self::Lapse => 'Lapse',
            self::Adjustment => 'Adjustment',
            self::Availed => 'Availed',
        };
    }

    /**
     * The `leave_balances` projection column this kind feeds. The balance
     * itself is the signed sum of every row; the columns keep each family's
     * total readable, which is what makes "why is my balance 4.5?"
     * answerable.
     */
    public function balanceColumn(): string
    {
        return match ($this) {
            self::Opening => 'opening',
            self::Accrual => 'accrued',
            self::CarryForward => 'carried_forward',
            self::Encashment => 'encashed',
            self::Lapse => 'lapsed',
            self::Adjustment => 'adjusted',
            self::Availed => 'availed',
        };
    }
}
