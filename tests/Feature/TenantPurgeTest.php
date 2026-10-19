<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUserRouting;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F9.5 — purging is gated by deletion age and a typed confirmation, and leaves an unrestorable tombstone. */
class TenantPurgeTest extends TestCase
{
    use IsolatesDatabase;

    private function deleted(int $daysAgo): Tenant
    {
        $t = Tenant::query()->where('slug', 'globex')->firstOrFail();
        $t->delete();
        Tenant::withTrashed()->whereKey($t->id)->update(['deleted_at' => now()->subDays($daysAgo)]);

        return Tenant::withTrashed()->findOrFail($t->id);
    }

    public function test_it_refuses_live_recent_and_unconfirmed_tenants(): void
    {
        $this->artisan('tenants:purge', ['tenant' => 'acme', '--confirm' => 'acme'])->assertFailed();   // not deleted

        $this->deleted(2);
        $this->artisan('tenants:purge', ['tenant' => 'globex', '--confirm' => 'globex'])->assertFailed(); // inside the restore window

        Tenant::withTrashed()->where('slug', 'globex')->update(['deleted_at' => now()->subDays(40)]);
        $this->artisan('tenants:purge', ['tenant' => 'globex'])->assertFailed();                          // no confirmation
        $this->assertTrue(TenantUserRouting::where('tenant_id', Tenant::withTrashed()->where('slug', 'globex')->value('id'))->exists());
    }

    public function test_a_confirmed_purge_removes_routing_audits_and_blocks_restore(): void
    {
        $t = $this->deleted(45);

        $this->artisan('tenants:purge', ['tenant' => 'globex', '--confirm' => 'globex'])->assertSuccessful();

        $this->assertFalse(TenantUserRouting::where('tenant_id', $t->id)->exists());
        $this->assertNotNull(Tenant::withTrashed()->find($t->id)->settings['purged_at'] ?? null);
        $this->assertTrue(DB::connection(config('tenancy.system.connection'))->table('audit_logs')->where('action', 'tenant.purged')->where('subject_id', $t->id)->exists());

        $this->artisan('tenants:purge', ['tenant' => 'globex', '--confirm' => 'globex'])->assertFailed();  // only once
    }
}
