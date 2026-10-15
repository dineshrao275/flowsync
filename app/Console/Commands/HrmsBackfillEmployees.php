<?php

namespace App\Console\Commands;

use App\Models\Hrms\Employee\Employee;
use App\Models\Tenant;
use App\Services\Hrms\Employee\EmployeeBackfill;
use App\Services\TenantLimits;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HRMS — backfill an employment record for every login that lacks one.
 *
 * A data migration, not a feature: it exists so the module is usable on a
 * tenant whose staff were onboarded before it existed, and so the 100 seeded
 * dev tenants do not need 1,000 records created by hand.
 */
class HrmsBackfillEmployees extends Command
{
    protected $signature = 'hrms:backfill-employees
        {--tenant= : Central id of a single tenant}
        {--all : Run for every tenant that has a database}
        {--dry-run : Report what would be created without writing anything}';

    protected $description = 'Create the missing HRMS employee records for existing tenant users';

    public function handle(EmployeeBackfill $backfill, TenantDatabaseManager $dbm, TenantLimits $limits): int
    {
        if (! $this->hasOneScope()) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->error('No tenant matched. Nothing to do.');

            return self::FAILURE;
        }

        $this->info(($dryRun ? 'Planning' : 'Backfilling')." employees for {$tenants->count()} tenant(s).");

        $rows = [];
        $failed = 0;

        foreach ($tenants as $tenant) {
            $row = $this->backfillTenant($tenant, $backfill, $dbm, $limits, $dryRun);

            if ($row === null) {
                $failed++;
                $rows[] = [$tenant->slug, '—', '—', '—', '—', 'failed'];

                continue;
            }

            $rows[] = [
                $tenant->slug,
                $row['users'],
                $row['created'],
                $row['already_linked'],
                $row['reallocated'],
                $this->planNote($row['projected'], $limits->limit($tenant, 'employees')),
            ];
        }

        $this->table(['Tenant', 'Users', 'Created', 'Already linked', 'Reallocated codes', 'Against plan limit'], $rows);

        if ($dryRun) {
            $this->info('Dry run — nothing was written.');
        }

        if ($failed > 0) {
            $this->error("{$failed} tenant(s) failed; see the errors above.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function hasOneScope(): bool
    {
        $scoped = $this->option('tenant') !== null;
        $all = (bool) $this->option('all');

        // Neither is a footgun and both is a contradiction, so both are refused
        // rather than one silently winning.
        if ($scoped === $all) {
            $this->error('Pass exactly one of --tenant=ID or --all.');

            return false;
        }

        return true;
    }

    /**
     * The tenants in scope.
     *
     * A named `--tenant=` is honoured whatever its status — an operator who
     * names a tenant is answering "is this one done?", and a failed or
     * suspended tenant is exactly where that answer is worth having.
     *
     * `--all` covers every tenant that has a database, keyed on `provisioned_at`
     * rather than a status list. A lifecycle-status filter would be a second
     * thing to keep in step with `TenantLifecycle`, and it would be wrong in
     * both directions: a `pending` tenant has no database to connect to, and a
     * suspended or deactivated one still holds its staff records.
     *
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
     * @return array{users: int, created: int, already_linked: int, reallocated: int, projected: int}|null
     *                                                                                                     Null when the tenant could not be processed.
     */
    private function backfillTenant(
        Tenant $tenant,
        EmployeeBackfill $backfill,
        TenantDatabaseManager $dbm,
        TenantLimits $limits,
        bool $dryRun
    ): ?array {
        try {
            return $dbm->using($tenant, function () use ($backfill, $dryRun) {
                $summary = $dryRun ? $backfill->plan() : $backfill->run();

                // The employee count has to be read on the tenant connection,
                // and a dry run wrote nothing — so the projected total adds the
                // rows the plan would have created.
                $summary['projected'] = Employee::count() + ($dryRun ? $summary['created'] : 0);

                return $summary;
            });
        } catch (Throwable $e) {
            $this->error("Tenant {$tenant->slug} (#{$tenant->id}): ".$e->getMessage());

            return null;
        }
    }

    /**
     * The resulting headcount against the tenant's plan.
     *
     * Reported, never enforced. The limit gates *new hires*, and refusing to
     * record the people a tenant already employs would make the migration
     * impossible on exactly the tenants that need it. A tenant pushed over its
     * limit by its own backfill should find out here, not by having its next
     * hire rejected with no explanation.
     */
    private function planNote(int $projected, mixed $limit): string
    {
        if (! is_numeric($limit)) {
            return "{$projected} (unlimited)";
        }

        return $projected > (int) $limit ? "{$projected} over {$limit}" : "{$projected} of {$limit}";
    }
}
