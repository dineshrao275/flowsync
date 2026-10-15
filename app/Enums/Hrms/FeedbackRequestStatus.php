<?php

namespace App\Enums\Hrms;

enum FeedbackRequestStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Submitted => 'Submitted',
            self::Declined => 'Declined',
        };
    }

    /**
     * Hex, never a Tailwind palette name (P2.6 lesson, HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => '#d97706',
            self::Submitted => '#059669',
            self::Declined => '#4b5563',
        };
    }
}
