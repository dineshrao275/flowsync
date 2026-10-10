<?php

namespace App\Enums\Hrms;

/**
 * The flavours of a secondary (matrix) reporting line. None of them approves
 * anything — the primary `manager_id` stays the line of record.
 */
enum DottedLineKind: string
{
    case Functional = 'functional';
    case Project = 'project';
    case Dotted = 'dotted';

    public function label(): string
    {
        return match ($this) {
            self::Functional => 'Functional',
            self::Project => 'Project',
            self::Dotted => 'Dotted line',
        };
    }
}
