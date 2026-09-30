<?php

namespace App\Console\Commands;

use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Tenant;
use App\Services\Hrms\PerformanceService;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * HRMS — re-photograph goal evidence from task data.
 *
 * A schedule command and an operator command: nightly it sweeps every goal
 * (or one cycle), on demand it repairs a cycle whose evidence went stale.
 * Idempotent and safe to re-run — the refresh recomputes from the work
 * record instead of accumulating, so a second pass only corrects rows
 * whose tasks moved since. Evidence only: nothing here writes a rating.
 */
class HrmsPerformanceEvidence extends Command
{
    protected $signature = 'hrms:performance-evidence
        {--cycle= : A single cycle id (default all cycles)}
        {--tenant= : Central id of a single tenant}
        {--all : Run for every provisioned tenant}
        {--dry-run : Report goal counts without writing anything}';

    protected $description = 'Refresh performance goal evidence from task data';

    public function handle(TenantDatabaseManager $dbm): int
    {
        $scoped = $this->option('tenant') !== null;
        $all = (bool) $this->option('all');

        if ($scoped === $all) {
            $this->error('Pass exactly one of --tenant=ID or --all.');

            return self::FAILURE;
        }

        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->error('No tenant matched. Nothing to do.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cycleId = $this->option('cycle') !== null ? (int) $this->option('cycle') : null;

        Log::channel('hrms')->info('performance.evidence.command_started', [
            'cycle_id' => $cycleId,
            'tenants' => $tenants->count(),
            'dry_run' => $dryRun,
        ]);

        $rows = [];
        $failed = 0;

        foreach ($tenants as $tenant) {
            $result = $this->sweepTenant($tenant, $dbm, $cycleId, $dryRun);

            if ($result === null) {
                $failed++;
                $rows[] = [$tenant->slug, '—', 'failed'];

                continue;
            }

            $rows[] = [$tenant->slug, "{$result['refreshed']}/{$result['goals']}", $dryRun ? 'dry run' : 'refreshed'];
        }

        $this->table(['Tenant', 'Goals refreshed/total', 'Mode'], $rows);

        Log::channel('hrms')->info('performance.evidence.command_finished', [
            'cycle_id' => $cycleId,
            'tenants' => $tenants->count(),
            'failed' => $failed,
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
     * @return Collection<int, Tenant>
     */
    private function tenants(): Collection
    {
        if ($this->option('tenant') !== null) {
            $tenant = Tenant::find($this->option('tenant'));

            return collect($tenant === null ? [] : [$tenant]);
        }

        return Tenant::query()->where('status', 'active')->orderBy('id')->get();
    }

    /**
     * @return array{goals: int, refreshed: int}|null
     */
    private function sweepTenant(Tenant $tenant, TenantDatabaseManager $dbm, ?int $cycleId, bool $dryRun): ?array
    {
        try {
            return $dbm->using($tenant, function () use ($tenant, $cycleId, $dryRun): ?array {
                $cycles = $cycleId !== null
                    ? PerformanceCycle::query()->whereKey($cycleId)->get()
                    : PerformanceCycle::query()->orderBy('id')->get();

                if ($cycleId !== null && $cycles->isEmpty()) {
                    $this->warn("Tenant {$tenant->slug} has no cycle {$cycleId} — skipped.");

                    return ['goals' => 0, 'refreshed' => 0];
                }

                if ($dryRun) {
                    $goals = $cycles->sum(fn (PerformanceCycle $cycle): int => $cycle->goals()->count());

                    return ['goals' => $goals, 'refreshed' => 0];
                }

                $totals = ['goals' => 0, 'refreshed' => 0];

                foreach ($cycles as $cycle) {
                    $result = app(PerformanceService::class)->refreshCycleEvidence($cycle);
                    $totals['goals'] += $result['goals'];
                    $totals['refreshed'] += $result['refreshed'];
                }

                return $totals;
            });
        } catch (\Throwable $exception) {
            $this->error("Tenant {$tenant->slug}: {$exception->getMessage()}");

            return null;
        }
    }
}
