<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Hrms\Retention\HrmsRetention as RetentionService;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HRMS — report (default) or purge rows past the keep window.
 *
 * A schedule command and an operator command: on the clock it reports what
 * would go, on demand it deletes per table in batches. Bare invocation is
 * always a dry run — deletion needs `--apply` spelled out — and the
 * append-only `hrms_audit_logs` never appears in either mode, because
 * keeping that ledger is a legal decision, not a storage one.
 */
class HrmsRetention extends Command
{
    protected $signature = 'hrms:retention
        {--tenant= : Central id of a single tenant}
        {--all : Run for every provisioned tenant}
        {--months= : Keep window in months (default the tenant setting)}
        {--apply : Delete rows past the window (default reports only)}';

    protected $description = 'Report or purge HRMS rows past the retention window';

    public function handle(TenantDatabaseManager $dbm): int
    {
        $scoped = $this->option('tenant') !== null;
        $all = (bool) $this->option('all');

        if ($scoped === $all) {
            $this->error('Pass exactly one of --tenant=ID or --all.');

            return self::FAILURE;
        }

        $months = null;

        if ($this->option('months') !== null) {
            $months = (int) $this->option('months');

            if ($months < 1) {
                $this->error('--months must be at least 1.');

                return self::FAILURE;
            }
        }

        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->error('No tenant matched. Nothing to do.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        Log::channel('hrms')->info('retention.command_started', [
            'tenants' => $tenants->count(),
            'months' => $months,
            'apply' => $apply,
        ]);

        $rows = [];
        $failed = 0;

        foreach ($tenants as $tenant) {
            $result = $this->sweepTenant($tenant, $dbm, $months, $apply);

            if ($result === null) {
                $failed++;
                $rows[] = [$tenant->slug, '—', '—', '—', '—', '—', 'failed'];

                continue;
            }

            $rows[] = [$tenant->slug, ...array_values($result), $apply ? 'purged' : 'dry run'];
        }

        $this->table(['Tenant', 'Payslips', 'Exits', 'Attendance', 'Documents', 'Data access', 'Mode'], $rows);

        Log::channel('hrms')->info('retention.command_finished', [
            'tenants' => $tenants->count(),
            'failed' => $failed,
        ]);

        if (! $apply) {
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
     * @return array<string, int>|null
     */
    private function sweepTenant(Tenant $tenant, TenantDatabaseManager $dbm, ?int $months, bool $apply): ?array
    {
        try {
            return $dbm->using($tenant, function () use ($months, $apply): array {
                $retention = app(RetentionService::class);

                return $apply ? $retention->purge($months) : $retention->report($months);
            });
        } catch (Throwable $exception) {
            $this->error("Tenant {$tenant->slug}: {$exception->getMessage()}");

            return null;
        }
    }
}
