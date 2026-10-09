<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesVisibleTasks;
use App\Models\Priority;
use App\Models\Project;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportsController extends Controller
{
    use ScopesVisibleTasks;

    public function overview(Request $request): JsonResponse
    {
        $range = $this->range($request);

        $query = $this->visibleTaskQuery($request->user())
            ->whereNull('tasks.archived_at');

        // Optional window: tasks created or completed inside it. Without
        // bounds the pool is everything visible (the historic behavior).
        if ($range !== null) {
            $query->where(fn ($builder) => $builder
                ->whereDate('tasks.created_at', '>=', $range['from'])
                ->whereDate('tasks.created_at', '<=', $range['to'])
                ->orWhere(fn ($done) => $done
                    ->whereDate('tasks.completed_at', '>=', $range['from'])
                    ->whereDate('tasks.completed_at', '<=', $range['to'])));
        }

        // Aggregate in SQL: the pool can be every visible task in the tenant,
        // so nothing here may hydrate Task models (no eager loads, no get()).
        $query->setEagerLoads([]);

        $today = now()->toDateString();
        $totals = (clone $query)->reorder()->selectRaw(
            'count(*) as total,'
            .' sum(case when tasks.completed_at is null then 1 else 0 end) as open_count,'
            .' sum(case when tasks.completed_at is null and tasks.due_date is not null and date(tasks.due_date) < ? then 1 else 0 end) as overdue_count',
            [$today],
        )->first();

        $total = (int) $totals->total;
        $open = (int) $totals->open_count;

        return response()->json([
            'scope' => $this->userManagesAllTasks($request->user()) ? 'all' : 'member',
            'range' => $range,
            'totals' => [
                'total' => $total,
                'open' => $open,
                'done' => $total - $open,
                'overdue' => (int) $totals->overdue_count,
            ],
            'by_status' => $this->distribution($query, 'status_id', 'No status', TaskStatus::class, fn ($m) => [$m->name, $m->color ?? '#94a3b8']),
            'by_priority' => $this->distribution($query, 'priority_id', 'No priority', Priority::class, fn ($m) => [$m->name, $m->color ?? '#94a3b8']),
            'by_assignee' => $this->distribution($query, 'assignee_id', 'Unassigned', User::class, fn ($m) => [$m->name, $m->name ? '#6366f1' : '#94a3b8']),
            'by_project' => $this->distribution($query, 'project_id', 'Unknown project', Project::class, fn ($m) => [$m->name, '#0ea5e9']),
        ]);
    }

    /**
     * One GROUP BY per dimension. A null foreign key is the `none` bucket; a key
     * whose row no longer exists keeps the dimension's fallback label.
     *
     * @param  class-string<Model>  $related
     * @param  callable(Model): array{0: ?string, 1: string}  $describe  [label, color]
     * @return list<array{key: string, label: string, count: int, open: int, done: int, color: string}>
     */
    private function distribution(Builder $query, string $column, string $fallbackLabel, string $related, callable $describe): array
    {
        $rows = (clone $query)->reorder()
            ->selectRaw("tasks.{$column} as group_id, count(*) as total, sum(case when tasks.completed_at is null then 1 else 0 end) as open_count")
            ->groupBy("tasks.{$column}")
            ->get();

        $models = $related::whereIn('id', $rows->pluck('group_id')->filter()->all())->get()->keyBy('id');
        $fallbackColor = $column === 'project_id' ? '#0ea5e9' : '#94a3b8';

        return $rows->map(function ($row) use ($models, $describe, $fallbackLabel, $fallbackColor) {
            $model = $row->group_id === null ? null : $models->get($row->group_id);
            [$label, $color] = $model ? $describe($model) : [null, $fallbackColor];
            $count = (int) $row->total;
            $open = (int) $row->open_count;

            return [
                'key' => (string) ($row->group_id ?? 'none'),
                'label' => $label ?? $fallbackLabel,
                'color' => $color,
                'count' => $count,
                'open' => $open,
                'done' => $count - $open,
            ];
        })->sortBy([['count', 'desc'], ['label', 'asc']])->values()->all();
    }

    /**
     * Optional ?from=&to= (YYYY-MM-DD) window, echoed back so the client
     * can label the charts. Null when unfiltered (the historic "everything
     * visible" behavior). Capped like the analytics range.
     *
     * @return array{from: string, to: string, days: int}|null
     */
    private function range(Request $request): ?array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if (! isset($data['from']) && ! isset($data['to'])) {
            return null;
        }

        $to = isset($data['to']) ? Carbon::parse($data['to']) : Carbon::today();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(13);

        if ($from->diffInDays($to) > 365) {
            abort(422, 'Range covers at most 366 days.');
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $from->diffInDays($to) + 1,
        ];
    }
}
