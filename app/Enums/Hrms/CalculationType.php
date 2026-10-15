<?php

namespace App\Enums\Hrms;

enum CalculationType: string
{
    case Fixed = 'fixed';
    case PercentageOfCtc = 'percentage_of_ctc';
    case PercentageOfBasic = 'percentage_of_basic';
    case Formula = 'formula';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed',
            self::PercentageOfCtc => 'Percentage of CTC',
            self::PercentageOfBasic => 'Percentage of basic',
            self::Formula => 'Formula',
        };
    }
}
