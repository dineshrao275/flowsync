<?php

namespace App\Enums;

enum TaskStatusCategory: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case Done = 'done';

    public function isDone(): bool
    {
        return $this === self::Done;
    }
}
