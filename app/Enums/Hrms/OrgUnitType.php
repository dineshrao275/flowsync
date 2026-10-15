<?php

namespace App\Enums\Hrms;

/**
 * The kinds of org node that live beside the department tree. An employee sits
 * in at most one unit of each type.
 */
enum OrgUnitType: string
{
    case BusinessUnit = 'business_unit';
    case LegalEntity = 'legal_entity';
    case CostCenter = 'cost_center';

    public function label(): string
    {
        return match ($this) {
            self::BusinessUnit => 'Business unit',
            self::LegalEntity => 'Legal entity',
            self::CostCenter => 'Cost centre',
        };
    }

    /** Hex, never a palette name (P2.6 lesson). */
    public function color(): string
    {
        return match ($this) {
            self::BusinessUnit => '#4f46e5',
            self::LegalEntity => '#0f766e',
            self::CostCenter => '#b45309',
        };
    }
}
