<?php

namespace App\Enums\Hrms;

enum RegularizationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Hex, never a Tailwind palette name: pills render
     * `backgroundColor: \`${color}22\``, so a palette name silently yields an
     * invalid colour (P2.6 lesson, pinned by HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => '#d97706',
            self::Approved => '#059669',
            self::Rejected => '#dc2626',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
