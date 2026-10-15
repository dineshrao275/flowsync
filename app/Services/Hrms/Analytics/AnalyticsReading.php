<?php

namespace App\Services\Hrms\Analytics;

use App\Enums\Hrms\CaseTaskStatus;
use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Analytics/HRMS — the shared read machinery.
 *
 * One base, eight readers: the filter-to-query translation (`employees()`,
 * `window()`), the five-minute cache with filter-hashed keys, and the
 * completion fraction live here once, so the readers differ only in what
 * they count. Person-level rows travel only when the reader is asked for
 * them (see each reader's `$detailed` flag) — the base never decides.
 */
abstract class AnalyticsReading
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Employee>
     */
    protected function employees(array $filters): Builder
    {
        // Qualified: breakdowns join departments/locations/types onto this
        // builder, and a bare `id` order would go ambiguous on the join.
        $query = Employee::query()->orderBy('employees.id');

        foreach (['department_id', 'location_id', 'employment_type_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where("employees.{$field}", (int) $filters[$field]);
            }
        }

        if (! empty($filters['manager_id'])) {
            $query->where('employees.manager_id', (int) $filters['manager_id']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{string, string} Inclusive Y-m-d bounds, defaulting to the current month.
     */
    protected function window(array $filters, int $defaultDays = 0): array
    {
        if (! empty($filters['from']) || ! empty($filters['to'])) {
            $to = ! empty($filters['to']) ? Carbon::parse((string) $filters['to'])->toDateString() : today()->toDateString();
            $from = ! empty($filters['from'])
                ? Carbon::parse((string) $filters['from'])->toDateString()
                : Carbon::parse($to)->subDays($defaultDays)->toDateString();

            return [$from, $to];
        }

        return [today()->copy()->startOfMonth()->toDateString(), today()->toDateString()];
    }

    protected function completionPercent($query): float
    {
        $total = (clone $query)->count();

        if ($total === 0) {
            return 0.0;
        }

        $done = (clone $query)->where('status', CaseTaskStatus::Done->value)->count();

        return round($done / $total * 100, 1);
    }

    protected function remember(string $method, array $filters, callable $build): array
    {
        return Cache::remember(
            'hrms.analytics.'.$method.'.'.md5((string) json_encode($filters)),
            300,
            $build,
        );
    }
}
