<?php

namespace App\Enums\Hrms;

enum CompOffSource: string
{
    case Weekend = 'weekend';
    case Holiday = 'holiday';
    case Special = 'special';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Weekend => 'Weekend',
            self::Holiday => 'Holiday',
            self::Special => 'Special day',
            self::Manual => 'Manual grant',
        };
    }
}
