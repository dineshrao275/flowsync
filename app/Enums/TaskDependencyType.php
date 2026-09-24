<?php

namespace App\Enums;

enum TaskDependencyType: string
{
    case Blocks = 'blocks';
    case RelatedTo = 'related_to';

    public function isBlocker(): bool
    {
        return $this === self::Blocks;
    }
}
