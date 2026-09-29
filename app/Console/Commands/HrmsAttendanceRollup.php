<?php

namespace App\Console\Commands;

use App\Models\Hrms\Employee\Employee;
use App\Models\Tenant;
use App\Services\Hrms\AttendanceService;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HRMS — close the attendance day for one tenant or all of them.
 *
 * A schedule command and an operator command: nightly it ensures every
 * active employee owns a day row (absent when nothing else explains the
 * day), on demand it repairs a date whose rollup never ran. Idempotent and
 * safe to re-run — `computeDay` re-photographs instead of duplicating, so a
 * second pass only corrects rows whose punches arrived late.
 */
class HrmsAttendanceRollup extends Command
{
    protected $signature = 'hrms:attendance-rollup
        {--date= : Work date YYYY-MM-DD (default today)}
        {--tenant= : Central id of a single tenant}
        {--all : Run for every provisioned tenant}
        {--dry-run : Report active-employee counts without writing anything}';

    protected $description = 'Ensure attendance day rows exist for every active employee';

    public function handle(AttendanceService $attendance, TenantDatabaseManager $dbm): int
    {
        $scoped = $this->option('tenant') !== null;
        $all = (bool) $this->option('all');

        // Exactly one scope, as in the sibling HRMS commands: neither is a
        // footgun and both is a contradiction, so both are refused rather
        // than one silently winning.
        if ($scoped === $all) {
            $this->error('Pass exactly one of --tenant=ID or --all.');

            return self::FAILURE;
        }

        $date = $this->workDate();

        if ($date === null) {
            return self::FAILURE;
        }

        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->error('No tenant matched. Nothing to do.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $started = microtime(true);

        Log::channel('hrms')->info('attendance.rollup.command_started', [
            'work_date' => $date,
            'tenants' => $tenants->count(),
            'dry_run' => $dryRun,
        ]);

        $rows = [];
        $failed = 0;
        $ensured = 0;

        foreach ($tenants as $tenant) {
            $result = $this->rollupTenant($tenant, $attendance, $dbm, $date, $dryRun);

            if ($result === null) {
                $failed++;
                $rows[] = [$tenant->slug, '—', 'failed'];

                continue;
            }

            $ensured += $dryRun ? 0 : $result;
            $rows[] = [$tenant->slug, $result, $dryRun ? 'dry run' : 'ensured'];
        }

        $this->table(['Tenant', 'Day rows', 'Mode'], $rows);

        Log::channel('hrms')->info('attendance.rollup.command_finished', [
            'work_date' => $date,
            'tenants' => $tenants->count(),
            'failed' => $failed,
            'ensured' => $ensured,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        if ($dryRun) {
            $this->info('Dry run — nothing was written.');
        }

        if ($failed > 0) {
            $this->error("{$failed} tenant(s) failed; see the errors above.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The date to close: strict YYYY-MM-DD, never the future.
     *
     * A future rollup would stamp `absent` on days nobody has lived yet, and
     * a loose parse turns `--date=tomorrow` into a work date by accident —
     * both are refused here rather than repaired downstream.
     */
    private function workDate(): ?string
    {
        $raw = $this->option('date');

        if ($raw === null || trim((string) $raw) === '') {
            return today()->toDateString();
        }

        $raw = trim((string) $raw);

        // createFromFormat throws on garbage rather than returning false,
        // so the refusal lives in a catch, not in a null check.
        try {
            $date = Carbon::createFromFormat('Y-m-d', $raw);
        } catch (Throwable) {
            $date = false;
        }

        if ($date === false || $date->format('Y-m-d') !== $raw) {
            $this->error('Pass --date= as YYYY-MM-DD.');

            return null;
        }

        if ($date->isFuture()) {
            $this->error('The rollup cannot close a date that has not happened yet.');

            return null;
        }

        return $date->toDateString();
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function tenants(): Collection
    {
        if ($this->option('tenant') !== null) {
            $tenant = Tenant::find($this->option('tenant'));

            return $tenant ? collect([$tenant]) : collect();
        }

        return Tenant::query()
            ->whereNotNull('provisioned_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return int|null Ensured rows, or null when the tenant failed.
     */
    private function rollupTenant(
        Tenant $tenant,
        AttendanceService $attendance,
        TenantDatabaseManager $dbm,
        string $date,
        bool $dryRun,
    ): ?int {
        try {
            return $dbm->using($tenant, function () use ($attendance, $date, $dryRun): int {
                if ($dryRun) {
                    return Employee::query()->active()->count();
                }

                return $attendance->rollup($date)['ensured'];
            });
        } catch (Throwable $exception) {
            $this->error("Tenant {$tenant->slug} (#{$tenant->id}): ".$exception->getMessage());

            return null;
        }
    }
}
