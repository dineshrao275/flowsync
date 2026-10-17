<?php

namespace App\Services\Reports;

use App\Http\Controllers\Concerns\ScopesVisibleTasks;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\User;
use App\Services\Hrms\HrmsScope;
use App\Support\Hrms\HrmsSchema;
use App\Support\Tms\TmsSchema;
use Illuminate\Validation\ValidationException;

/**
 * The report builder's engine (F11.9). Datasets, columns and filters are a whitelist — a report is never SQL — and every
 * dataset reads through the SAME row scope as its normal screens (visible tasks; expense claims by HR view scope), so a
 * saved or scheduled report can never show its owner more than they could see by browsing.
 */
class ReportRunner
{
    use ScopesVisibleTasks;

    public const MAX_ROWS = 5000;

    /** @return array<string, array{label: string, columns: array<string, string>, filters: list<string>, available: bool}> */
    public function catalog(): array
    {
        return [
            'tasks' => [
                'label' => 'Tasks',
                'available' => TmsSchema::present(),
                'columns' => ['key' => 'Key', 'title' => 'Title', 'project' => 'Project', 'status' => 'Status', 'priority' => 'Priority', 'assignee' => 'Assignee', 'due_date' => 'Due date', 'estimate_minutes' => 'Estimate (min)', 'created_at' => 'Created', 'completed_at' => 'Completed'],
                'filters' => ['project_id', 'assignee_id', 'open_only', 'due_from', 'due_to'],
            ],
            'expenses' => [
                'label' => 'Expense claims',
                'available' => HrmsSchema::present(),
                'columns' => ['claim_number' => 'Claim', 'employee' => 'Employee', 'purpose' => 'Purpose', 'status' => 'Status', 'total_amount' => 'Total', 'claim_date' => 'Claim date', 'period' => 'Period'],
                'filters' => ['status', 'period_year', 'period_month'],
            ],
        ];
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $filters
     * @return array{columns: array<string, string>, rows: list<array<string, mixed>>, truncated: bool}
     *
     * @throws ValidationException
     */
    public function run(User $user, string $dataset, array $columns, array $filters, int $limit = self::MAX_ROWS): array
    {
        $def = $this->catalog()[$dataset] ?? null;
        if ($def === null || ! $def['available']) {
            throw ValidationException::withMessages(['dataset' => __('That dataset is not available.')]);
        }

        $picked = array_values(array_intersect($columns, array_keys($def['columns'])));
        if ($picked === []) {
            throw ValidationException::withMessages(['columns' => __('Choose at least one column.')]);
        }

        $rows = $dataset === 'tasks' ? $this->tasks($user, $picked, $filters, $limit + 1) : $this->expenses($user, $picked, $filters, $limit + 1);

        return [
            'columns' => array_intersect_key($def['columns'], array_flip($picked)),
            'rows' => array_slice($rows, 0, $limit),
            'truncated' => count($rows) > $limit,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function tasks(User $user, array $cols, array $f, int $limit): array
    {
        $q = $this->visibleTaskQuery($user)->orderByDesc('tasks.id');
        if (! empty($f['project_id'])) {
            $q->where('tasks.project_id', (int) $f['project_id']);
        }
        if (! empty($f['assignee_id'])) {
            $q->where('tasks.assignee_id', (int) $f['assignee_id']);
        }
        if (! empty($f['open_only'])) {
            $q->whereNull('tasks.completed_at');
        }
        if (! empty($f['due_from'])) {
            $q->whereDate('tasks.due_date', '>=', $f['due_from']);
        }
        if (! empty($f['due_to'])) {
            $q->whereDate('tasks.due_date', '<=', $f['due_to']);
        }

        return $q->limit($limit)->get()->map(function ($t) use ($cols) {
            $all = [
                'key' => $t->key, 'title' => $t->title, 'project' => $t->project?->name, 'status' => $t->status?->name, 'priority' => $t->priority?->name,
                'assignee' => $t->assignee?->name, 'due_date' => $t->due_date?->toDateString(), 'estimate_minutes' => $t->estimate_minutes,
                'created_at' => $t->created_at?->toDateTimeString(), 'completed_at' => $t->completed_at?->toDateTimeString(),
            ];

            return array_intersect_key($all, array_flip($cols));
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function expenses(User $user, array $cols, array $f, int $limit): array
    {
        if (! HrmsScope::canRead($user, 'hrms.expenses')) {
            return [];
        }

        $q = ExpenseClaim::query()->with('employee:id,name')->orderByDesc('id');
        if (! HrmsScope::seesAll($user, 'hrms.expenses')) {
            $q->whereIn('employee_id', HrmsScope::employeeIdsFor($user, 'hrms.expenses') ?: [0]);
        }
        foreach (['status', 'period_year', 'period_month'] as $k) {
            if (! empty($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }

        return $q->limit($limit)->get()->map(function ($c) use ($cols) {
            $all = [
                'claim_number' => $c->claim_number, 'employee' => $c->employee?->name, 'purpose' => $c->purpose, 'status' => $c->status->value,
                'total_amount' => (string) $c->total_amount, 'claim_date' => $c->claim_date?->toDateString(), 'period' => sprintf('%04d-%02d', $c->period_year, $c->period_month),
            ];

            return array_intersect_key($all, array_flip($cols));
        })->all();
    }
}
