<?php

namespace App\Enums\Hrms;

enum HolidayType: string
{
    case Public = 'public';
    case Restricted = 'restricted';
    case Optional = 'optional';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Restricted => 'Restricted',
            self::Optional => 'Optional',
        };
    }

    /**
     * Whether the day is off by default. Public holidays close the office;
     * restricted ones are working days unless taken as optional, and
     * optional ones count only when declared taken.
     */
    public function isDayOffByDefault(): bool
    {
        return $this === self::Public;
    }
}
