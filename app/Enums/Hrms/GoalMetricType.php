<?php

namespace App\Enums\Hrms;

enum GoalMetricType: string
{
    case None = 'none';
    case TaskCompletion = 'task_completion';
    case WorklogHours = 'worklog_hours';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No metric',
            self::TaskCompletion => 'Task completion',
            self::WorklogHours => 'Logged hours',
            self::Manual => 'Manual',
        };
    }
}
