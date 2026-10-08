<?php

namespace App\Enums\Hrms;

enum TaskOwnerScope: string
{
    case Hr = 'hr';
    case Manager = 'manager';
    case Employee = 'employee';
    case It = 'it';

    /**
     * Who the item is *for*, not who may click it done: a manager-scoped item
     * is the hiring manager’s job, and the detail screen groups the checklist
     * by exactly this so each party sees their own pile.
     */
    public function label(): string
    {
        return match ($this) {
            self::Hr => 'HR',
            self::Manager => 'Manager',
            self::Employee => 'Employee',
            self::It => 'IT',
        };
    }
}
