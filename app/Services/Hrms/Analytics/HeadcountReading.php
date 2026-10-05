<?php

namespace App\Services\Hrms\Analytics;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Analytics/HRMS — who is here, where, and how fast the door turns.
 *
 * Attrition is exits over current headcount times a hundred: a crude
 * ratio, honestly labelled, for a tile — workforce science books the
 * cohort version, and this tells the reader which one it is by naming
 * the window in the key.
 */
class HeadcountReading extends AnalyticsReading
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function read(array $filters = []): array
    {
        return $this->remember('headcount', $filters, function () use ($filters): array {
            $base = $this->employees($filters);

            $month = today()->startOfMonth()->toDateString();
            $total = (clone $base)->count();

            return [
                'total' => $total,
                'by_status' => $this->breakdown($base, null),
                'by_department' => $this->breakdown($base, 'departments', 'department_id'),
                'by_location' => $this->breakdown($base, 'locations', 'location_id'),
                'by_employment_type' => $this->breakdown($base, 'employment_types', 'employment_type_id'),
                'new_hires_this_month' => (clone $base)->whereDate('joining_date', '>=', $month)->count(),
                'exits_this_month' => Employee::query()->whereDate('exit_date', '>=', $month)->count(),
                'attrition_3mo_percent' => $this->attrition($total),
            ];
        });
    }

    /**
     * @return array<string, int>
     */
    private function breakdown($base, ?string $table, ?string $field = null): array
    {
        if ($table === null) {
            return (clone $base)->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')->all();
        }

        return (clone $base)->join($table, "{$table}.id", '=', "employees.{$field}")
            ->select("{$table}.name", DB::raw('count(*) as total'))->groupBy("{$table}.name")->pluck('total', 'name')->all();
    }

    private function attrition(int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        $exits = Employee::query()->whereDate('exit_date', '>=', today()->subMonths(3)->toDateString())->count();

        return round($exits / $total * 100, 1);
    }
}
