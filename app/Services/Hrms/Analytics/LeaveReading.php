<?php

namespace App\Services\Hrms\Analytics;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Payroll\EmployeeSalaryStructure;
use App\Support\Hrms\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Analytics/HRMS — leave taken, waiting, and what the unused pile costs.
 *
 * Two privacy tiers share one reader: counts and type splits always
 * travel; pending rows and top consumers travel on request; the expiry
 * liability travels only behind its own flag, because it prices balances
 * off salary structures — pay data wearing a leave costume, gated like
 * pay. The liability is an estimate (monthly gross over thirty), and says
 * so where it is defined.
 */
class LeaveReading extends AnalyticsReading
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function read(array $filters = [], bool $detailed = true, bool $includeLiability = false): array
    {
        $key = $filters + ['detailed' => $detailed, 'liability' => $includeLiability];

        return $this->remember('leave', $key, function () use ($filters, $detailed, $includeLiability): array {
            $ids = $this->employees($filters)->pluck('employees.id');

            $pendingCount = LeaveRequest::query()->whereIn('employee_id', $ids)
                ->whereIn('status', ['pending', 'submitted'])
                ->count();

            return [
                'by_type' => $this->byType($ids),
                'pending_approvals' => $pendingCount,
                'pending_items' => $detailed ? $this->pendingItems($ids) : [],
                'top_consumers' => $detailed ? $this->topConsumers($ids) : [],
                'expiry_liability' => $includeLiability ? $this->liability($ids) : null,
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byType(Collection $ids): array
    {
        return LeaveRequest::query()->whereIn('employee_id', $ids)
            ->where('status', 'approved')
            ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->select('leave_types.name', DB::raw('count(*) as total'), DB::raw('sum(total_days) as days'))
            ->groupBy('leave_types.name')->get()
            ->map(fn ($row): array => ['type' => $row->name, 'requests' => $row->total, 'days' => (float) $row->days])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingItems(Collection $ids): array
    {
        return LeaveRequest::query()->whereIn('employee_id', $ids)
            ->whereIn('status', ['pending', 'submitted'])
            ->with('employee:id,employee_code,name')
            ->orderByDesc('id')->limit(10)->get()
            ->map(fn (LeaveRequest $ask): array => [
                'id' => $ask->id, 'employee' => $ask->employee?->displayName(),
                'from_date' => $ask->from_date->toDateString(), 'to_date' => $ask->to_date->toDateString(),
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topConsumers(Collection $ids): array
    {
        $consumers = LeaveRequest::query()->whereIn('employee_id', $ids)
            ->where('status', 'approved')
            ->select('employee_id', DB::raw('sum(total_days) as days'))
            ->groupBy('employee_id')->orderByDesc('days')->limit(5)->get();
        $names = Employee::query()->whereIn('id', $consumers->pluck('employee_id'))->pluck('name', 'id');

        return $consumers->map(fn ($row): array => [
            'name' => $names[$row->employee_id] ?? "#{$row->employee_id}", 'days' => (float) $row->days,
        ])->all();
    }

    private function liability(Collection $ids): string
    {
        $total = Money::zero();

        $balances = LeaveBalance::query()->whereIn('employee_id', $ids)->get(['employee_id', 'balance']);

        $rates = EmployeeSalaryStructure::query()->where('is_current', true)
            ->whereIn('employee_id', $balances->pluck('employee_id')->unique())
            ->pluck('gross_monthly', 'employee_id');

        foreach ($balances->groupBy('employee_id') as $employeeId => $rows) {
            $days = $rows->sum(fn ($row): float => (float) $row->balance);

            if ($days <= 0 || ! isset($rates[$employeeId])) {
                continue;
            }

            $total = $total->add(
                Money::fromDecimal((string) $rates[$employeeId])->dividedBy('30')->multiply((string) $days),
            );
        }

        return $total->toDecimal();
    }
}
