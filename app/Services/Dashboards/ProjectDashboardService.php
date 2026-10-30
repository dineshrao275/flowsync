<?php

declare(strict_types=1);

namespace App\Services\Dashboards;

use App\Models\Project;
use App\Models\ProjectDashboard;
use App\Models\User;
use App\Services\Tasks\TaskReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Project dashboards: a fixed catalogue of widgets, a per-person (or shared-default) layout, and live
 * data. Every widget reads the caller's row-scoped task query (`TaskReader::filteredQuery`), so a
 * dashboard never shows a number built from tasks the caller cannot read.
 */
class ProjectDashboardService
{
    /** @var list<string> */
    public const WIDGETS = [
        'overview', 'status_breakdown', 'priority_breakdown', 'assignee_load',
        'overdue_list', 'created_vs_done', 'due_soon',
    ];

    public const DEFAULT = ['overview', 'status_breakdown', 'assignee_load', 'overdue_list', 'created_vs_done'];

    public function __construct(private readonly TaskReader $reader) {}

    /** @return list<string> */
    public function layoutFor(Project $project, User $user): array
    {
        $own = ProjectDashboard::query()->where('project_id', $project->id)->where('user_id', $user->id)->first();
        $shared = ProjectDashboard::query()->where('project_id', $project->id)->whereNull('user_id')->first();

        return $this->clean(($own ?? $shared)?->widgets ?? self::DEFAULT);
    }

    /** @param list<string> $widgets */
    public function save(Project $project, User $user, array $widgets, bool $shared): void
    {
        $clean = $this->clean($widgets);

        if ($clean === []) {
            throw ValidationException::withMessages(['widgets' => __('Choose at least one widget.')]);
        }

        $owner = $shared ? null : $user->id;
        ProjectDashboard::query()->updateOrCreate(['project_id' => $project->id, 'user_id' => $owner], ['widgets' => $clean]);
    }

    /** Drop the caller's own arrangement so the shared default (or built-in layout) applies again. */
    public function reset(Project $project, User $user): void
    {
        ProjectDashboard::query()->where('project_id', $project->id)->where('user_id', $user->id)->delete();
    }

    /**
     * @param  list<string>  $widgets
     * @return array<string, mixed>
     */
    public function data(Project $project, User $user, array $widgets): array
    {
        $out = [];
        foreach ($widgets as $widget) {
            $out[$widget] = $this->widget($widget, $project, $user);
        }

        return $out;
    }

    private function widget(string $widget, Project $project, User $user): mixed
    {
        $base = fn (): Builder => $this->reader->filteredQuery($project, [], $user)->setEagerLoads([])->reorder();

        return match ($widget) {
            'overview' => $this->overview($base()),
            'status_breakdown' => $this->breakdown($base(), 'status_id', 'task_statuses', 'name', 'color'),
            'priority_breakdown' => $this->breakdown($base(), 'priority_id', 'priorities', 'name', 'color'),
            'assignee_load' => $this->assigneeLoad($base()),
            'overdue_list' => $this->taskList($base()->whereNull('tasks.completed_at')->whereDate('tasks.due_date', '<', now()->toDateString())->orderBy('tasks.due_date')),
            'due_soon' => $this->taskList($base()->whereNull('tasks.completed_at')->whereDate('tasks.due_date', '>=', now()->toDateString())->whereDate('tasks.due_date', '<=', now()->addDays(7)->toDateString())->orderBy('tasks.due_date')),
            'created_vs_done' => $this->createdVsDone($base()),
            default => null,
        };
    }

    /** @return array<string, int> */
    private function overview(Builder $q): array
    {
        $row = (clone $q)->select([])->selectRaw(
            'count(*) as total,'
            .' sum(case when tasks.completed_at is null then 1 else 0 end) as open_count,'
            .' sum(case when tasks.completed_at is null and tasks.due_date is not null and date(tasks.due_date) < ? then 1 else 0 end) as overdue',
            [now()->toDateString()],
        )->first();

        return ['total' => (int) $row->total, 'open' => (int) $row->open_count, 'done' => (int) $row->total - (int) $row->open_count, 'overdue' => (int) $row->overdue];
    }

    /** @return list<array{label: string, count: int, color: string}> */
    private function breakdown(Builder $q, string $column, string $table, string $nameCol, string $colorCol): array
    {
        $rows = (clone $q)->select("tasks.$column as k", DB::raw('count(*) as c'))->groupBy("tasks.$column")->get();
        $names = DB::table($table)->whereIn('id', $rows->pluck('k')->filter())->get(['id', $nameCol, $colorCol])->keyBy('id');

        return $rows->map(fn ($r) => [
            'label' => $r->k ? ($names[$r->k]->{$nameCol} ?? '—') : __('None'),
            'count' => (int) $r->c,
            'color' => $r->k ? ($names[$r->k]->{$colorCol} ?? '#94a3b8') : '#cbd5e1',
        ])->sortByDesc('count')->values()->all();
    }

    /** @return list<array{label: string, open: int}> open tasks per assignee, largest first */
    private function assigneeLoad(Builder $q): array
    {
        $rows = (clone $q)->whereNull('tasks.completed_at')->select('tasks.assignee_id as k', DB::raw('count(*) as c'))->groupBy('tasks.assignee_id')->get();
        $names = User::query()->whereIn('id', $rows->pluck('k')->filter())->pluck('name', 'id');

        return $rows->map(fn ($r) => ['label' => $r->k ? ($names[$r->k] ?? '—') : __('Unassigned'), 'open' => (int) $r->c])
            ->sortByDesc('open')->values()->take(10)->all();
    }

    /** @return list<array{id: int, key: string, title: string, due_date: ?string}> */
    private function taskList(Builder $q): array
    {
        return (clone $q)->limit(8)->get(['tasks.id', 'tasks.key', 'tasks.title', 'tasks.due_date'])
            ->map(fn ($t) => ['id' => $t->id, 'key' => $t->key, 'title' => $t->title, 'due_date' => $t->due_date?->toDateString()])->all();
    }

    /** @return list<array{date: string, created: int, done: int}> the last 14 days */
    private function createdVsDone(Builder $q): array
    {
        $from = now()->subDays(13)->startOfDay();
        $created = (clone $q)->where('tasks.created_at', '>=', $from)->get(['tasks.created_at'])->groupBy(fn ($t) => $t->created_at->toDateString())->map->count();
        $done = (clone $q)->where('tasks.completed_at', '>=', $from)->get(['tasks.completed_at'])->groupBy(fn ($t) => Carbon::parse($t->completed_at)->toDateString())->map->count();

        return collect(range(0, 13))->map(function ($i) use ($from, $created, $done): array {
            $d = $from->copy()->addDays($i)->toDateString();

            return ['date' => $d, 'created' => (int) ($created[$d] ?? 0), 'done' => (int) ($done[$d] ?? 0)];
        })->all();
    }

    /**
     * @param  list<mixed>  $widgets
     * @return list<string>
     */
    private function clean(array $widgets): array
    {
        return array_values(array_unique(array_filter($widgets, fn ($w) => in_array($w, self::WIDGETS, true))));
    }
}
