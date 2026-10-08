<?php

namespace App\Services\Hrms;

use App\Models\User;
use App\Services\Hrms\Analytics\AssetReading;
use App\Services\Hrms\Analytics\AttendanceReading;
use App\Services\Hrms\Analytics\DocumentReading;
use App\Services\Hrms\Analytics\HeadcountReading;
use App\Services\Hrms\Analytics\LeaveReading;
use App\Services\Hrms\Analytics\LifecycleReading;
use App\Services\Hrms\Analytics\PayrollReading;
use App\Services\Hrms\Analytics\PerformanceReading;

/**
 * Analytics/HRMS — the workforce dashboards' single front door.
 *
 * A thin orchestrator on purpose (the P3.2 split rule): the eight domain
 * readers in `Analytics/` own the counting, the caching and the privacy
 * tiers, and this owns nothing but the wiring — so a dashboard, a digest
 * command and a future export cannot reimplement one rule and drift from
 * the others. Method names are the dashboard tabs.
 */
class HrmsAnalyticsService
{
    public function __construct(
        private readonly HeadcountReading $headcount,
        private readonly AttendanceReading $attendance,
        private readonly LeaveReading $leave,
        private readonly LifecycleReading $lifecycle,
        private readonly PerformanceReading $performance,
        private readonly PayrollReading $payroll,
        private readonly DocumentReading $documents,
        private readonly AssetReading $assets,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function headcount(array $filters = []): array
    {
        return $this->headcount->read($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function attendance(array $filters = [], bool $detailed = true): array
    {
        return $this->attendance->read($filters, $detailed);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function leave(array $filters = [], bool $detailed = true, bool $includeLiability = false): array
    {
        return $this->leave->read($filters, $detailed, $includeLiability);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function lifecycle(array $filters = []): array
    {
        return $this->lifecycle->read($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function performance(array $filters = [], bool $includeRatings = false): array
    {
        return $this->performance->read($filters, $includeRatings);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payroll(array $filters = [], ?User $actor = null): array
    {
        return $this->payroll->read($filters, $actor);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function documents(array $filters = [], bool $detailed = true, bool $includeConfidential = false): array
    {
        return $this->documents->read($filters, $detailed, $includeConfidential);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function assets(array $filters = []): array
    {
        return $this->assets->read($filters);
    }
}
