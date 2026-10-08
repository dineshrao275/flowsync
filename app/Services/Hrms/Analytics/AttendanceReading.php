<?php

namespace App\Services\Hrms\Analytics;

use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Employee\Employee;

/**
 * Analytics/HRMS — presence in aggregate, names only on request.
 *
 * The counts always travel; the top-late and top-overtime boards travel
 * only when asked — the route asks behind its permission gate, digests
 * default redacted, and neither caller can coax names out of an aggregate
 * call.
 */
class AttendanceReading extends AnalyticsReading
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function read(array $filters = [], bool $detailed = true): array
    {
        return $this->remember('attendance', $filters + ['detailed' => $detailed], function () use ($filters, $detailed): array {
            [$from, $to] = $this->window($filters);

            $ids = $this->employees($filters)->pluck('employees.id');

            $rows = AttendanceDay::query()->whereIn('employee_id', $ids)
                ->whereDate('work_date', '>=', $from)
                ->whereDate('work_date', '<=', $to)
                ->get(['employee_id', 'status', 'worked_minutes', 'late_by_minutes', 'overtime_minutes']);

            return [
                'present_days' => $rows->filter(fn ($row): bool => $row->status->value === 'present')->count(),
                'absent_days' => $rows->filter(fn ($row): bool => $row->status->value === 'absent')->count(),
                'late_days' => $rows->where('late_by_minutes', '>', 0)->count(),
                'overtime_minutes' => $rows->sum('overtime_minutes'),
                'leave_days' => $rows->filter(fn ($row): bool => $row->status->value === 'leave')->count(),
                'average_worked_hours' => $rows->isEmpty() ? 0.0 : round($rows->sum('worked_minutes') / $rows->count() / 60, 2),
                'top_late' => $detailed ? $this->top($rows, 'late_by_minutes') : [],
                'top_overtime' => $detailed ? $this->top($rows, 'overtime_minutes') : [],
            ];
        });
    }

    /**
     * @return list<array{name: string, minutes: int}>
     */
    private function top($rows, string $column): array
    {
        $names = Employee::query()->whereIn('id', $rows->pluck('employee_id')->unique())->pluck('name', 'id');

        return $rows->groupBy('employee_id')
            ->map(fn ($days, $id): array => ['name' => $names[$id] ?? "#{$id}", 'minutes' => $days->sum($column)])
            ->sortByDesc('minutes')
            ->take(5)
            ->values()
            ->all();
    }
}
