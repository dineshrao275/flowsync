<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use App\Models\Task;
use Illuminate\Support\Str;

/** `feature/{KEY}-{slug}`: the key makes the branch match the task, the slug (<= 60 chars, [a-z0-9-]) makes it readable. */
final class BranchNamer
{
    public const SLUG_MAX = 60;

    public static function for(Task $task): string
    {
        $slug = trim((string) substr(Str::slug($task->title), 0, self::SLUG_MAX), '-');

        return 'feature/'.$task->key.($slug === '' ? '' : '-'.$slug);
    }
}
