<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\Project;
use App\Models\User;
use App\Support\Like;

/** Projects the caller belongs to (tenant managers: all). Same visibility rule as the global search. */
final class ListProjectsTool extends AbstractTool
{
    private const MAX = 20;

    public function name(): string
    {
        return 'list_projects';
    }

    public function description(): string
    {
        return 'List projects the user can access, optionally filtered by name or key. Use it to find a project id.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'text' => ['type' => 'string', 'maxLength' => 80],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX],
        ]);
    }

    public function product(): ?string
    {
        return 'tms';
    }

    public function permission(): ?string
    {
        return 'workspaces.view';
    }

    public function execute(User $user, array $args): ToolResult
    {
        $query = Project::query()->with('workspace')->withCount('tasks');

        if (! $user->hasPermission('workspaces.manage')) {
            $query->whereHas('members', fn ($members) => $members->where('user_id', $user->id));
        }
        if (! empty($args['text'])) {
            Like::any($query, ['name', 'key'], $args['text']);
        }

        $total = (clone $query)->count();
        $projects = $query->orderBy('name')->limit((int) ($args['limit'] ?? 10))->get();

        return new ToolResult(
            ['total' => $total, 'projects' => $projects->map(fn (Project $p) => [
                'id' => $p->id, 'key' => $p->key, 'name' => $p->name, 'workspace' => $p->workspace?->name, 'tasks' => $p->tasks_count,
            ])->all()],
            $projects->map(fn (Project $p) => [
                'type' => 'project',
                'title' => "{$p->key} · {$p->name}",
                'subtitle' => implode(' · ', array_filter([$p->workspace?->name, $p->tasks_count.' tasks'])),
                'badges' => [],
                'link' => AiLinks::project($p->id),
            ])->all(),
        );
    }
}
