<?php

namespace App\Console\Commands;

use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Tenant;
use App\Services\Hrms\Payroll\PayrollService;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * HRMS — refresh a review run's statutory lines from the current rulebook.
 *
 * An operator command for the day the expert edits a configuration after a
 * run calculated: base heads and adjustments stay, engine lines re-price.
 * `--force` rebuilds the run fully instead (the heavier hammer for when the
 * base itself is suspect). Anything past review refuses either way — use the
 * run's own transitions, not this command, to move history.
 */
class HrmsStatutoryRecompute extends Command
{
    protected $signature = 'hrms:statutory-recompute
        {--run= : Payroll run id within each scoped tenant}
        {--force : Rebuild the run fully instead of refreshing statutory lines}
        {--tenant= : Central id of a single tenant}
        {--all : Run for every provisioned tenant}';

    protected $description = 'Recompute statutory lines on a review payroll run';

    public function handle(TenantDatabaseManager $dbm): int
    {
        $scoped = $this->option('tenant') !== null;
        $all = (bool) $this->option('all');

        if ($scoped === $all) {
            $this->error('Pass exactly one of --tenant=ID or --all.');

            return self::FAILURE;
        }

        if ($this->option('run') === null) {
            $this->error('Pass --run=ID: recompute names its run.');

            return self::FAILURE;
        }

        $runId = (int) $this->option('run');
        $force = (bool) $this->option('force');
        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->error('No tenant matched. Nothing to do.');

            return self::FAILURE;
        }

        Log::channel('hrms')->info('statutory.recompute.command_started', [
            'run_id' => $runId,
            'tenants' => $tenants->count(),
            'force' => $force,
        ]);

        $rows = [];
        $failed = 0;

        foreach ($tenants as $tenant) {
            $result = $this->recomputeTenant($tenant, $dbm, $runId, $force);

            if ($result === null) {
                $failed++;
                $rows[] = [$tenant->slug, '—', 'failed'];

                continue;
            }

            $rows[] = [$tenant->slug, $result, $force ? 'full rebuild' : 'statutory refresh'];
        }

        $this->table(['Tenant', 'Payslips', 'Mode'], $rows);

        Log::channel('hrms')->info('statutory.recompute.command_finished', [
            'run_id' => $runId,
            'tenants' => $tenants->count(),
            'failed' => $failed,
        ]);

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
     * Recompute one tenant's run: the run id is tenant-local, so a tenant
     * without that run reports itself instead of failing the batch. A
     * non-review run refuses through the service, not through silence.
     */
    private function recomputeTenant(Tenant $tenant, TenantDatabaseManager $dbm, int $runId, bool $force): ?int
    {
        try {
            return $dbm->using($tenant, function () use ($tenant, $runId, $force): ?int {
                $run = PayrollRun::query()->find($runId);

                if ($run === null) {
                    $this->warn("Tenant {$tenant->slug} has no run {$runId} — skipped.");

                    return 0;
                }

                $summary = app(PayrollService::class)->recomputeStatutory($run, null, $force);

                return $summary['payslips'];
            });
        } catch (ValidationException $exception) {
            $this->error("Tenant {$tenant->slug}: ".implode(' ', array_merge(...array_values($exception->errors()))));

            return null;
        } catch (Throwable $exception) {
            $this->error("Tenant {$tenant->slug}: {$exception->getMessage()}");

            return null;
        }
    }
}
