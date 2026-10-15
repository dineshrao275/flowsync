<?php

namespace App\Services\Hrms\Analytics;

use App\Models\Hrms\Asset\Asset;
use App\Models\Hrms\Org\Department;
use Illuminate\Support\Facades\DB;

/**
 * Analytics/HRMS — where the hardware sits.
 *
 * Status counts for the register at a glance; per-department counts follow
 * the holder, not the shelf — an assigned laptop belongs to its person's
 * department, wherever it sleeps. Unassigned rows group under their own
 * name rather than vanishing from the table.
 */
class AssetReading extends AnalyticsReading
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function read(array $filters = []): array
    {
        return $this->remember('assets', $filters, function () use ($filters): array {
            $query = Asset::query();

            if (! empty($filters['category_id'])) {
                $query->where('category_id', (int) $filters['category_id']);
            }

            $byStatus = (clone $query)->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')->all();

            $perDepartment = (clone $query)->where('status', 'assigned')
                ->whereNotNull('assigned_to_employee_id')
                ->with('assignee:id,department_id')
                ->get()
                ->groupBy(fn (Asset $asset): string => (string) ($asset->assignee?->department_id ?? 'unassigned'));

            $deptNames = Department::query()->pluck('name', 'id');

            return [
                'by_status' => $byStatus,
                'per_department' => $perDepartment->map(fn ($rows, $departmentId): array => [
                    'department' => $deptNames[$departmentId] ?? $departmentId,
                    'assigned' => $rows->count(),
                ])->values()->all(),
            ];
        });
    }
}
