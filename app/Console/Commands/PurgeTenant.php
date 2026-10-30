<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Services\PlatformAudit;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * F9.5 — irreversible removal of a deleted tenant's DATA. Deliberately hard to run by accident:
 *  - the tenant must already be soft-deleted, and for longer than `tenancy.purge_after_days` (the restore window);
 *  - the operator must repeat the tenant's slug in `--confirm`;
 *  - the database is dropped, HR files and login routing are removed, and a `tenant.purged` audit row is written FIRST.
 * The central tenant row stays as a tombstone (name, slug, timestamps, `settings.purged_at`) because invoices and payments
 * reference it and billing records have their own retention rules; it can never be restored afterwards.
 */
class PurgeTenant extends Command
{
    protected $signature = 'tenants:purge {tenant : id or slug} {--confirm= : repeat the tenant slug to proceed} {--dry-run : only report what would happen}';

    protected $description = 'Permanently remove a soft-deleted tenant\'s database, files and login routing (keeps a billing tombstone)';

    public function handle(TenantDatabaseManager $dbm): int
    {
        $ref = (string) $this->argument('tenant');
        $tenant = Tenant::onlyTrashed()->where(fn ($q) => $q->where('slug', $ref)->orWhere('id', ctype_digit($ref) ? (int) $ref : 0))->first();

        if ($tenant === null) {
            $this->error('No soft-deleted tenant matches that id or slug. Only a deleted tenant can be purged.');

            return self::FAILURE;
        }
        if (($tenant->settings['purged_at'] ?? null) !== null) {
            $this->error('That tenant was already purged.');

            return self::FAILURE;
        }

        $days = (int) config('tenancy.purge_after_days', 30);
        if ($tenant->deleted_at->gt(now()->subDays($days))) {
            $this->error("Deleted {$tenant->deleted_at->diffForHumans()}; a tenant can be purged only after {$days} days (config tenancy.purge_after_days).");

            return self::FAILURE;
        }

        $routes = TenantUserRouting::where('tenant_id', $tenant->id)->count();
        $this->line("Tenant {$tenant->slug} (#{$tenant->id}): database, {$routes} login route(s) and HR files will be removed.");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if ($this->option('confirm') !== $tenant->slug) {
            $this->error("Refusing: pass --confirm={$tenant->slug} to proceed.");

            return self::FAILURE;
        }

        app(PlatformAudit::class)->record(null, 'tenant.purged', Tenant::class, $tenant->id, ['slug' => $tenant->slug, 'name' => $tenant->name, 'login_routes' => $routes]);

        $dropped = $dbm->dropDatabase($tenant);
        TenantUserRouting::where('tenant_id', $tenant->id)->delete();
        Storage::disk('local')->deleteDirectory("hrms/{$tenant->id}");

        $settings = (array) $tenant->settings;
        $settings['purged_at'] = now()->toIso8601String();
        unset($settings['sso']);
        $tenant->settings = $settings;
        $tenant->saveQuietly();

        $this->info($dropped ? 'Purged.' : 'Purged (the database was already gone).');

        return self::SUCCESS;
    }
}
