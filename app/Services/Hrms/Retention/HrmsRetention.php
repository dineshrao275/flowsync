<?php

namespace App\Services\Hrms\Retention;

use App\Enums\Hrms\OffboardingCaseStatus;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\Hrms\Shared\HrmsSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Retention/HRMS — reporting and purging rows past their keep window.
 *
 * Five tables, each with its own clock: payslips and documents age by
 * `created_at`, exits by last activity (a completed run updated years ago
 * is history; an open one is never purgeable no matter its age),
 * attendance by `work_date`, and the who-read-what ledger by `created_at`.
 * `hrms_audit_logs` is deliberately absent — append-only retention is a
 * legal decision, out of scope for a purge switch.
 *
 * Deletes run in id-cursor batches (portable across grammars — Postgres
 * has no `DELETE ... LIMIT`), and document bytes leave storage before
 * their rows leave the database, so a crash cannot orphan files nobody
 * references nor rows nobody can open.
 */
class HrmsRetention
{
    private const BATCH = 500;

    public function months(): int
    {
        $row = HrmsSetting::query()->find(HrmsSetting::SINGLETON_ID);

        return max(1, (int) ($row?->data_retention_months ?? config('hrms.settings_defaults.data_retention_months', 24)));
    }

    public function cutoff(?int $months = null): Carbon
    {
        return now()->subMonths($months ?? $this->months())->startOfDay();
    }

    /**
     * Rows past the window per table, without touching anything.
     *
     * @return array<string, int>
     */
    public function report(?int $months = null): array
    {
        $cutoff = $this->cutoff($months);

        return [
            'payslips' => Payslip::query()->where('created_at', '<', $cutoff)->count(),
            'exits' => $this->exitsQuery($cutoff)->count(),
            'attendance' => AttendanceDay::query()->where('work_date', '<', $cutoff->toDateString())->count(),
            'documents' => EmployeeDocument::query()->where('created_at', '<', $cutoff)->count(),
            'data_access' => HrmsDataAccessLog::query()->where('created_at', '<', $cutoff)->count(),
        ];
    }

    /**
     * Delete rows past the window per table, in batches.
     *
     * Exits and documents force-delete: both models soft-delete by
     * default, and a retention purge that leaves the PII readable behind
     * a `deleted_at` flag is not a purge.
     *
     * @return array<string, int>
     */
    public function purge(?int $months = null): array
    {
        $cutoff = $this->cutoff($months);

        return [
            'payslips' => $this->deleteInBatches(Payslip::query()->where('created_at', '<', $cutoff)),
            'exits' => $this->deleteInBatches($this->exitsQuery($cutoff), true),
            'attendance' => $this->deleteInBatches(AttendanceDay::query()->where('work_date', '<', $cutoff->toDateString())),
            'documents' => $this->purgeDocuments($cutoff),
            'data_access' => $this->deleteInBatches(HrmsDataAccessLog::query()->where('created_at', '<', $cutoff)),
        ];
    }

    /**
     * Closed exit runs only: an open run is someone's live departure, and
     * age does not make it purgeable.
     *
     * @return Builder<OffboardingCase>
     */
    private function exitsQuery(Carbon $cutoff): Builder
    {
        return OffboardingCase::query()
            ->whereIn('status', [OffboardingCaseStatus::Completed->value, OffboardingCaseStatus::Cancelled->value])
            ->where('updated_at', '<', $cutoff);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function deleteInBatches(Builder $query, bool $force = false): int
    {
        $deleted = 0;

        do {
            $ids = $query->clone()->limit(self::BATCH)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $doomed = $query->getModel()->newQuery()->whereKey($ids->all());
            $deleted += $force ? $doomed->forceDelete() : $doomed->delete();
        } while (true);

        return $deleted;
    }

    private function purgeDocuments(Carbon $cutoff): int
    {
        $deleted = 0;

        do {
            $rows = EmployeeDocument::query()
                ->where('created_at', '<', $cutoff)
                ->limit(self::BATCH)
                ->get(['id', 'file_disk', 'file_path']);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                if ($row->file_path !== null) {
                    Storage::disk($row->file_disk ?? 'local')->delete($row->file_path);
                }
            }

            $deleted += EmployeeDocument::query()->whereKey($rows->modelKeys())->forceDelete();
        } while (true);

        return $deleted;
    }
}
