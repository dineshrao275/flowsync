<?php

namespace App\Enums\Hrms;

enum SurveyType: string
{
    case Pulse = 'pulse';
    case Engagement = 'engagement';
    case OnboardingExit = 'onboarding_exit';
    case Exit = 'exit';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Pulse => 'Pulse',
            self::Engagement => 'Engagement',
            self::OnboardingExit => 'Onboarding exit',
            self::Exit => 'Exit',
            self::Custom => 'Custom',
        };
    }
}
