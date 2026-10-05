<?php

namespace App\Console\Commands;

use App\Models\Hrms\Analytics\ReportSchedule;
use App\Models\Tenant;
use App\Services\Hrms\Analytics\ReportDigestService;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HRMS — send due report digests and advance their cursors.
 *
 * A schedule command and an operator command: nightly it sends every due
 * digest, on demand it runs one tenant. Sending advances `next_run_at`,
 * so a second sweep finds nothing due — the "does not re-send" acceptance
 * is structural, asserted by running the command twice.
 */
class HrmsReportDigests extends Command
{
    protected $signature = 'hrms:report-digests
        {--tenant= : Central id of a single tenant}
        {--all : Run for every provisioned tenant}
        {--dry-run : Report due digests without writing anything}';

    protected $description = 'Send due HR report digests';

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

        Log::channel('hrms')->info('reports.digests.command_started', [
            'tenants' => $tenants->count(),
            'dry_run' => $dryRun,
        ]);

        $rows = [];
        $failed = 0;

        foreach ($tenants as $tenant) {
            $result = $this->sweepTenant($tenant, $dbm, $dryRun);

            if ($result === null) {
                $failed++;
                $rows[] = [$tenant->slug, '—', '—', 'failed'];

                continue;
            }

            $rows[] = [$tenant->slug, $result['due'], $result['sent'], $dryRun ? 'dry run' : 'sent'];
        }

        $this->table(['Tenant', 'Due', 'Sent', 'Mode'], $rows);

        Log::channel('hrms')->info('reports.digests.command_finished', [
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
     * @return array{due: int, sent: int}|null
     */
    private function sweepTenant(Tenant $tenant, TenantDatabaseManager $dbm, bool $dryRun): ?array
    {
        try {
            return $dbm->using($tenant, function () use ($dryRun): array {
                $due = ReportSchedule::query()->active()->due()->orderBy('id')->get();

                if ($dryRun) {
                    return ['due' => $due->count(), 'sent' => 0];
                }

                $sent = 0;

                foreach ($due as $schedule) {
                    $sent += app(ReportDigestService::class)->send($schedule)['sent'];
                }

                return ['due' => $due->count(), 'sent' => $sent];
            });
        } catch (Throwable $exception) {
            $this->error("Tenant {$tenant->slug}: {$exception->getMessage()}");

            return null;
        }
    }
}
