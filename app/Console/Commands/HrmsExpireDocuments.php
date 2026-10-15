<?php

namespace App\Console\Commands;

use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Tenant;
use App\Services\Hrms\DocumentService;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HRMS — close the employee documents whose expiry has passed.
 *
 * A compliance command, not a migration: it runs on a schedule and on demand,
 * and it changes statuses rather than schema. Rejected rows are left alone —
 * a rejection already closed the row — and only live rows with an `expires_at`
 * in the past move to `expired`.
 */
class HrmsExpireDocuments extends Command
{
    protected $signature = 'hrms:documents-expiry
        {--tenant= : Central id of a single tenant}
        {--all : Run for every tenant that has a database}
        {--dry-run : Report past-due documents without changing them}';

    protected $description = 'Mark past-due HRMS employee documents expired';

    public function handle(DocumentService $documents, TenantDatabaseManager $dbm): int
    {
        $scoped = $this->option('tenant') !== null;
        $all = (bool) $this->option('all');

        // Exactly one scope, as in the employee backfill: neither is a
        // footgun and both is a contradiction, so both are refused rather
        // than one silently winning.
        if ($scoped === $all) {
            $this->error('Pass exactly one of --tenant=ID or --all.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->error('No tenant matched. Nothing to do.');

            return self::FAILURE;
        }

        $rows = [];
        $failed = 0;

        foreach ($tenants as $tenant) {
            $result = $this->expireTenant($tenant, $documents, $dbm, $dryRun);

            if ($result === null) {
                $failed++;
                $rows[] = [$tenant->slug, '—', '—', 'failed'];

                continue;
            }

            $rows[] = [$tenant->slug, $result[0], $result[1], $dryRun ? 'dry run' : 'expired'];
        }

        $this->table(['Tenant', 'Past due', 'Closed', 'Mode'], $rows);

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

            return $tenant ? collect([$tenant]) : collect();
        }

        return Tenant::query()
            ->whereNotNull('provisioned_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{int, int}|null Past-due count, then closed count.
     */
    private function expireTenant(Tenant $tenant, DocumentService $documents, TenantDatabaseManager $dbm, bool $dryRun): ?array
    {
        try {
            return $dbm->using($tenant, function () use ($documents, $dryRun): array {
                $pastDue = EmployeeDocument::query()->awaitingAction()->expiringBy(today())->count();
                $closed = $dryRun ? 0 : $documents->expireDue()->count();

                return [$pastDue, $closed];
            });
        } catch (Throwable $exception) {
            $this->error("Tenant {$tenant->slug} (#{$tenant->id}): ".$exception->getMessage());

            return null;
        }
    }
}
