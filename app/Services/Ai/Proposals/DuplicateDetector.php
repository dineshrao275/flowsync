<?php

declare(strict_types=1);

namespace App\Services\Ai\Proposals;

use App\Models\Project;
use App\Models\User;
use App\Support\VisibleTasks;

/**
 * Flags proposed tasks that look like work already in the project. It only compares against tasks the
 * requesting user may see: telling someone "this matches ACME-9" about a task they cannot open would
 * leak its existence. A flag is advice (the item starts unselected), never a block.
 */
final class DuplicateDetector
{
    private const POOL = 2000;

    private const SIMILARITY = 88.0;

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public function flag(array $items, Project $project, User $user): array
    {
        $existing = VisibleTasks::query($user, $user->hasPermission('workspaces.manage'))
            ->where('tasks.project_id', $project->id)->whereNull('tasks.archived_at')
            ->orderByDesc('tasks.id')->limit(self::POOL)->get(['tasks.id', 'tasks.key', 'tasks.title', 'tasks.project_id'])
            ->map(fn ($t) => [$this->norm((string) $t->title), (string) $t->key])->all();

        $seen = [];
        foreach ($items as &$item) {
            $norm = $this->norm($item['title']);
            $match = $this->match($norm, $existing) ?? ($seen[$norm] ?? null);

            $item['duplicate_of'] = $match;
            $item['selected'] = $match === null && $item['selected'];
            $seen[$norm] ??= $item['title'].' (this list)';
        }

        return $items;
    }

    /** @param list<array{0:string,1:string}> $existing */
    private function match(string $norm, array $existing): ?string
    {
        foreach ($existing as [$title, $key]) {
            if ($title === $norm) {
                return $key;
            }
            similar_text($title, $norm, $percent);
            if ($percent >= self::SIMILARITY) {
                return $key;
            }
        }

        return null;
    }

    private function norm(string $title): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title)));
    }
}
