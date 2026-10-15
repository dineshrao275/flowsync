<?php

namespace App\Console\Commands\Concerns;

use App\Models\Tenant;
use App\Support\TenantDatabaseManager;
use Closure;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Console — the `--tenant=ID XOR --all` scope shared by the HRMS fleet sweeps.
 *
 * `tenantsOrFail()` returns the tenants in scope (provisioned ones for `--all`)
 * or null after printing why not; `forEachTenant()` runs a closure inside each
 * tenant's database, isolating failures so one broken tenant never stops the
 * fleet, and returns the per-tenant rows plus a failure count.
 */
trait RunsPerTenant
{
    /** @return Collection<int, Tenant>|null */
    protected function tenantsOrFail(): ?Collection
    {
        $scoped = $this->option('tenant') !== null;

        if ($scoped === (bool) $this->option('all')) {
            $this->error('Pass exactly one of --tenant=ID or --all.');

            return null;
        }

        $tenants = $scoped
            ? collect(Tenant::find($this->option('tenant')))->filter()
            : Tenant::query()->whereNotNull('provisioned_at')->orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->error('No tenant matched. Nothing to do.');

            return null;
        }

        return $tenants;
    }

    /**
     * @param  Collection<int, Tenant>  $tenants
     * @param  Closure(Tenant): array<int, mixed>  $work  returns the table cells after the slug
     * @return array{rows: array<int, array<int, mixed>>, failed: int}
     */
    protected function forEachTenant(Collection $tenants, Closure $work): array
    {
        $dbm = app(TenantDatabaseManager::class);
        $rows = [];
        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                $rows[] = [$tenant->slug, ...$dbm->using($tenant, fn () => $work($tenant))];
            } catch (Throwable $exception) {
                $failed++;
                $this->error("Tenant {$tenant->slug} (#{$tenant->id}): ".$exception->getMessage());
                $rows[] = [$tenant->slug, 'failed'];
            }
        }

        return ['rows' => $rows, 'failed' => $failed];
    }
}
