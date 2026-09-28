<?php

namespace App\Enums\Hrms;

enum PunchSource: string
{
    case Web = 'web';
    case Mobile = 'mobile';
    case Kiosk = 'kiosk';
    case Import = 'import';
    case Auto = 'auto';
    case Regularized = 'regularized';

    /**
     * `regularized` marks punches the approval flow rewrote; `auto` marks
     * rows the rollup or an import created without a human hand on a clock.
     * Both matter when someone asks “who said they were here”.
     */
    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Mobile => 'Mobile',
            self::Kiosk => 'Kiosk',
            self::Import => 'Import',
            self::Auto => 'Auto',
            self::Regularized => 'Regularized',
        };
    }
}
