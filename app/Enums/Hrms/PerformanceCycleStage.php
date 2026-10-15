<?php

namespace App\Enums\Hrms;

enum PerformanceCycleStage: string
{
    case GoalSetting = 'goal_setting';
    case CheckIn = 'check_in';
    case SelfReview = 'self_review';
    case ManagerReview = 'manager_review';
    case Calibration = 'calibration';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::GoalSetting => 'Goal setting',
            self::CheckIn => 'Check-in',
            self::SelfReview => 'Self review',
            self::ManagerReview => 'Manager review',
            self::Calibration => 'Calibration',
            self::Completed => 'Completed',
        };
    }

    /**
     * Hex, never a Tailwind palette name (P2.6 lesson, HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::GoalSetting => '#0284cb',
            self::CheckIn => '#7c3aed',
            self::SelfReview => '#d97706',
            self::ManagerReview => '#059669',
            self::Calibration => '#dc2626',
            self::Completed => '#4b5563',
        };
    }
}
