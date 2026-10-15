<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Hrms\Approval\ApprovalSla;
use App\Support\Hrms\HrmsSchema;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * HRMS — approval SLA sweep (P2.4): reminders before a stage is due and one
 * escalation once it is overdue, each at most once per stage.
 *
 * Idempotent (the stamps on the approval make a re-run a no-op), so the
 * hourly schedule and an operator re-run are both safe. Tenants without the
 * HRMS tables, or not yet migrated to approvals v2, are skipped.
 */
class HrmsApprovalsSla extends Command
{
    protected $signature = 'hrms:approvals-sla
        {--tenant= : Central id of a single tenant}
        {--all : Run for every active tenant}
        {--dry-run : Report what would be sent without writing anything}';

    protected $description = 'Remind and escalate approvals that are due or overdue';

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
        $rows = [];
        $failed = 0;

        foreach ($tenants as $tenant) {
            $result = $this->sweepTenant($tenant, $dbm, $dryRun);

            if ($result === null) {
                $failed++;
                $rows[] = [$tenant->slug, '—', '—', 'failed'];

                continue;
            }

            $rows[] = [$tenant->slug, $result['reminded'], $result['escalated'], $dryRun ? 'dry run' : 'swept'];
        }

        $this->table(['Tenant', 'Reminded', 'Escalated', 'Mode'], $rows);

        Log::channel('hrms')->info('approvals.sla.command_finished', ['tenants' => $tenants->count(), 'failed' => $failed]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, Tenant> */
    private function tenants(): Collection
    {
        if ($this->option('tenant') !== null) {
            $tenant = Tenant::find($this->option('tenant'));

            return collect($tenant === null ? [] : [$tenant]);
        }

        return Tenant::query()->where('status', 'active')->orderBy('id')->get();
    }

    /** @return array{reminded: int, escalated: int}|null */
    private function sweepTenant(Tenant $tenant, TenantDatabaseManager $dbm, bool $dryRun): ?array
    {
        try {
            return $dbm->using($tenant, function () use ($dryRun): array {
                if (! HrmsSchema::present()
                    || ! Schema::hasColumn('approvals', 'escalated_at')) {
                    return ['reminded' => 0, 'escalated' => 0];
                }

                return app(ApprovalSla::class)->sweep($dryRun);
            });
        } catch (Throwable $exception) {
            $this->error("Tenant {$tenant->slug}: {$exception->getMessage()}");

            return null;
        }
    }
}
