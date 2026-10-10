<?php

namespace App\Enums\Hrms;

/**
 * How the steps of one stage settle (approvals v2, P2.4).
 *
 * A `sequential` step is a stage of one — the only shape that existed before
 * v2, so it stays the default and every old row reads as it did.
 */
enum ApprovalStepMode: string
{
    case Sequential = 'sequential';
    case ParallelAny = 'parallel_any';
    case ParallelAll = 'parallel_all';

    public function label(): string
    {
        return match ($this) {
            self::Sequential => 'One after another',
            self::ParallelAny => 'Any one of the group',
            self::ParallelAll => 'Everyone in the group',
        };
    }

    public function isParallel(): bool
    {
        return $this !== self::Sequential;
    }
}
