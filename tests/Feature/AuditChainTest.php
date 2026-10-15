<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\Security\AuditChain;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P8.6 - audit hash chain, tamper detection, export evidence and per-plan retention. */
class AuditChainTest extends TestCase
{
    use IsolatesDatabase;

    private function write(string $action, array $data = []): AuditLog
    {
        return AuditLog::create(['action' => $action, 'subject_type' => null, 'subject_id' => null, 'data' => $data, 'actor_id' => null, 'ip_address' => '127.0.0.1']);
    }

    private function central()
    {
        return DB::connection('iso_system');
    }

    public function test_rows_are_chained_in_id_order_and_verify_clean(): void
    {
        $a = $this->write('chain.one', ['b' => 2, 'a' => 1]);
        $b = $this->write('chain.two');

        $rowA = $this->central()->table('audit_logs')->find($a->id);
        $rowB = $this->central()->table('audit_logs')->find($b->id);

        $this->assertNotNull($rowA->hash);
        $this->assertSame($rowA->hash, $rowB->prev_hash);
        $report = app(AuditChain::class)->verify();
        $this->assertTrue($report['ok'], json_encode($report));
        $this->assertSame($rowB->hash, $report['head']);
        $this->artisan('audit:verify')->assertExitCode(0);
    }

    public function test_editing_a_sealed_row_is_detected_at_that_row(): void
    {
        $this->write('chain.one');
        $victim = $this->write('chain.two');
        $this->write('chain.three');

        $this->central()->table('audit_logs')->where('id', $victim->id)->update(['action' => 'chain.forged']);

        $report = app(AuditChain::class)->verify();
        $this->assertFalse($report['ok']);
        $this->assertSame($victim->id, $report['break']['id']);
        $this->artisan('audit:verify')->assertExitCode(1);
    }

    public function test_deleting_a_middle_row_is_detected(): void
    {
        $this->write('chain.one');
        $victim = $this->write('chain.two');
        $after = $this->write('chain.three');

        $this->central()->table('audit_logs')->where('id', $victim->id)->delete();

        $report = app(AuditChain::class)->verify();
        $this->assertFalse($report['ok']);
        $this->assertSame($after->id, $report['break']['id']);
    }

    public function test_rows_written_before_the_chain_are_legacy_not_failures(): void
    {
        $legacy = $this->write('legacy.row');
        // Everything up to here predates the chain (seeded rows included).
        $this->central()->table('audit_logs')->where('id', '<=', $legacy->id)->update(['hash' => null, 'prev_hash' => null]);
        $this->write('chain.after');

        $report = app(AuditChain::class)->verify();

        $this->assertTrue($report['ok'], json_encode($report));
        $this->assertGreaterThanOrEqual(1, $report['legacy']);
    }

    public function test_the_export_carries_hashes_and_pins_the_chain_head(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'superadmin@flowsync.test', 'password' => 'password'])->assertOk();
        $head = app(AuditChain::class)->head();

        $response = $this->get('/api/system/audit-logs/export')->assertOk();

        $response->assertHeader('X-Audit-Chain-Head', $head);
        $this->assertStringContainsString('hash,prev_hash', $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'audit.exported']);
        $this->getJson('/api/system/audit-logs/verify')->assertOk()->assertJsonPath('ok', true);
    }

    public function test_verification_needs_audit_view_and_tenant_users_are_refused(): void
    {
        $this->loginAs('admin@flowsync.test');
        $this->getJson('/api/system/audit-logs/verify')->assertForbidden();
    }

    public function test_retention_prunes_by_plan_leaves_tombstones_and_keeps_the_chain_valid(): void
    {
        $acme = $this->acme();
        $globex = $this->globex();
        $acme->update(['limits_override' => ['audit_retention_days' => 30]]);

        Carbon::setTestNow(now()->subDays(40));
        $oldAcme = $this->write('tenant.event', ['tenant_id' => $acme->id]);
        $oldGlobex = $this->write('tenant.event', ['tenant_id' => $globex->id]);
        $oldPlatform = $this->write('platform.event');
        Carbon::setTestNow();
        $recentAcme = $this->write('tenant.event', ['tenant_id' => $acme->id]);

        $this->artisan('audit:prune --dry-run')->assertExitCode(0);
        $this->assertNotNull(AuditLog::find($oldAcme->id));

        $this->artisan('audit:prune')->assertExitCode(0);

        $this->assertNull(AuditLog::find($oldAcme->id));
        $this->assertNotNull(AuditLog::find($oldGlobex->id), 'no retention on that plan: kept forever');
        $this->assertNotNull(AuditLog::find($oldPlatform->id), 'platform-only rows are never pruned');
        $this->assertNotNull(AuditLog::find($recentAcme->id));
        $this->assertDatabaseHas('audit_log_tombstones', ['audit_log_id' => $oldAcme->id, 'tenant_id' => $acme->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'audit.retention_pruned']);

        $report = app(AuditChain::class)->verify();
        $this->assertTrue($report['ok'], json_encode($report));
        $this->assertSame(1, $report['tombstones']);
    }

    public function test_the_prune_is_scheduled(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $commands = collect($this->app->make(Schedule::class)->events())
            ->map(fn ($e) => (string) $e->command)->implode("\n");

        $this->assertStringContainsString('audit:prune', $commands);
    }
}
