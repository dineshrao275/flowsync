<?php

namespace App\Enums\Hrms;

enum DocumentVisibility: string
{
    case Employee = 'employee';
    case Hr = 'hr';
    case Manager = 'manager';
    case Owner = 'owner';

    /**
     * Who may see the row — not how sensitive it is. Sensitivity is the
     * separate `confidential` boolean, because “only HR” and “sensitive
     * enough for the extra permission” are different answers.
     */
    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Employee',
            self::Hr => 'HR',
            self::Manager => 'Manager',
            self::Owner => 'Owner',
        };
    }
}
