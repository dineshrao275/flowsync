<?php

declare(strict_types=1);

namespace App\Enums\Hrms;

enum PunchDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'In',
            self::Out => 'Out',
        };
    }
}
