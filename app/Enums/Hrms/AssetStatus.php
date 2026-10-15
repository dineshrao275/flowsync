<?php

namespace App\Enums\Hrms;

enum AssetStatus: string
{
    case Available = 'available';
    case Assigned = 'assigned';
    case Maintenance = 'maintenance';
    case Retired = 'retired';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Assigned => 'Assigned',
            self::Maintenance => 'Maintenance',
            self::Retired => 'Retired',
            self::Lost => 'Lost',
        };
    }

    /**
     * Hex, never a Tailwind palette name (P2.6 lesson, HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::Available => '#059669',
            self::Assigned => '#0284cb',
            self::Maintenance => '#d97706',
            self::Retired => '#4b5563',
            self::Lost => '#dc2626',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Retired || $this === self::Lost;
    }
}
