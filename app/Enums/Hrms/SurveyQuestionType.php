<?php

namespace App\Enums\Hrms;

enum SurveyQuestionType: string
{
    case Scale = 'scale';
    case Text = 'text';
    case MultipleChoice = 'multiple_choice';
    case YesNo = 'yes_no';
    case Nps = 'nps';

    public function label(): string
    {
        return match ($this) {
            self::Scale => 'Scale',
            self::Text => 'Free text',
            self::MultipleChoice => 'Multiple choice',
            self::YesNo => 'Yes / no',
            self::Nps => 'NPS',
        };
    }
}
