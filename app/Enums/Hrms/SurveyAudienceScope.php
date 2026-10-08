<?php

namespace App\Enums\Hrms;

enum SurveyAudienceScope: string
{
    case All = 'all';
    case Department = 'department';
    case Role = 'role';
    case Location = 'location';
    case Explicit = 'explicit';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Everyone',
            self::Department => 'Department',
            self::Role => 'Role',
            self::Location => 'Location',
            self::Explicit => 'Explicit list',
        };
    }
}
