<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskDependencyType: string
{
    case Blocks = 'blocks';
    case RelatedTo = 'related_to';
    case Duplicates = 'duplicates';
    case Clones = 'clones';

    public function isBlocker(): bool
    {
        return $this === self::Blocks;
    }
}
