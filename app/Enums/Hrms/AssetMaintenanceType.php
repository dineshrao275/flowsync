<?php

namespace App\Enums\Hrms;

enum AssetMaintenanceType: string
{
    case Repair = 'repair';
    case Service = 'service';
    case Upgrade = 'upgrade';
    case Inspection = 'inspection';

    public function label(): string
    {
        return match ($this) {
            self::Repair => 'Repair',
            self::Service => 'Service',
            self::Upgrade => 'Upgrade',
            self::Inspection => 'Inspection',
        };
    }
}
