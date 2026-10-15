<?php

namespace App\Services\Hrms\Analytics;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\Hrms\Lifecycle\OffboardingCaseTask;
use App\Models\Hrms\Lifecycle\OnboardingCase;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;

/**
 * Analytics/HRMS — how long exits take and how checklists complete.
 *
 * Datediffs run in PHP, not SQL: SQLite's julianday() has no PostgreSQL
 * twin, and these tables stay small enough that the loop costs nothing
 * next to the dashboard's other reads. Offboarding duration reads
 * created-to-updated on completed rows — the case carries no explicit
 * completed stamp, so last write stands in for sign-off.
 */
class LifecycleReading extends AnalyticsReading
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function read(array $filters = []): array
    {
        return $this->remember('lifecycle', $filters, function (): array {
            $onboardingDays = OnboardingCase::query()->whereNotNull('completed_at')
                ->get(['started_at', 'completed_at'])
                ->map(fn (OnboardingCase $case): int => $case->started_at->diffInDays($case->completed_at));
            $offboardingDays = OffboardingCase::query()->where('status', 'completed')
                ->get(['created_at', 'updated_at'])
                ->map(fn (OffboardingCase $case): int => $case->created_at->diffInDays($case->updated_at));
            $firstDay = Employee::query()->whereNotNull('joining_date')
                ->get(['created_at', 'joining_date'])
                ->map(fn (Employee $employee): int => $employee->created_at->diffInDays($employee->joining_date));

            return [
                'onboarding_avg_days' => $onboardingDays->isEmpty() ? null : round($onboardingDays->avg(), 1),
                'offboarding_avg_days' => $offboardingDays->isEmpty() ? null : round($offboardingDays->avg(), 1),
                'onboarding_completion_percent' => $this->completionPercent(OnboardingCaseTask::query()),
                'offboarding_completion_percent' => $this->completionPercent(OffboardingCaseTask::query()),
                'time_to_first_day_avg' => $firstDay->isEmpty() ? null : round($firstDay->avg(), 1),
            ];
        });
    }
}
