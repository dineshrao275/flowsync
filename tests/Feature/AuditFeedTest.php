<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SystemUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * H-7 guard: the platform audit feed must page over the whole trail (the old
 * implementation merged the latest 100 rows per source in memory, so `total`
 * and `last_page` were both wrong past row 100), support a CSV export, and
 * order ties deterministically.
 */
class AuditFeedTest extends TestCase
{
    use IsolatesDatabase;

    private function loginSuperAdmin(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    /**
     * Insert one audit row with an explicit timestamp; `created_at` is set on
     * the instance so Eloquent's timestamp stamping does not clobber it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function seedAudit(array $attributes, ?Carbon $at = null): AuditLog
    {
        $at ??= now();

        $log = new AuditLog($attributes);
        $log->created_at = $at;
        $log->updated_at = $at;
        $log->save();

        return $log;
    }

    public function test_the_feed_paginates_the_full_trail_not_the_latest_100(): void
    {
        $this->loginSuperAdmin();
        AuditLog::query()->delete();

        $base = now()->subDays(2);
        for ($i = 0; $i < 105; $i++) {
            $this->seedAudit(
                ['action' => 'seed.event', 'data' => ['seq' => $i]],
                $base->copy()->addMinutes($i)
            );
        }

        $first = $this->getJson('/api/system/audit-logs?per_page=25')->assertOk();
        $first->assertJsonPath('pagination.total', 105)
            ->assertJsonPath('pagination.last_page', 5)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonCount(25, 'items');

        // The oldest rows — exactly the ones a 100-row cap discards — are on
        // the last page, and the newest row leads.
        $last = $this->getJson('/api/system/audit-logs?per_page=25&page=5')->assertOk();
        $last->assertJsonCount(5, 'items')
            ->assertJsonPath('items.4.data.seq', 0);
        $first->assertJsonPath('items.0.data.seq', 104);
    }

    public function test_ties_are_broken_by_id_descending(): void
    {
        $this->loginSuperAdmin();
        AuditLog::query()->delete();

        $tie = now()->subHour();
        $a = $this->seedAudit(['action' => 'tie.event'], $tie);
        $b = $this->seedAudit(['action' => 'tie.event'], $tie);
        $c = $this->seedAudit(['action' => 'tie.event'], $tie);

        $this->getJson('/api/system/audit-logs?type=audit')
            ->assertOk()
            ->assertJsonPath('items.0.id', 'a'.$c->id)
            ->assertJsonPath('items.1.id', 'a'.$b->id)
            ->assertJsonPath('items.2.id', 'a'.$a->id);
    }

    public function test_tenant_filter_reads_the_json_payload_and_the_subject_id(): void
    {
        $this->loginSuperAdmin();
        AuditLog::query()->delete();

        $globex = $this->globex();

        $this->seedAudit(['action' => 'hrms.updated', 'data' => ['tenant_id' => $globex->id]]);
        $this->seedAudit(['action' => 'tenant.status_changed', 'subject_type' => 'tenants', 'subject_id' => $globex->id]);
        $this->seedAudit(['action' => 'other.event', 'data' => ['tenant_id' => $this->acme()->id]]);

        $this->getJson('/api/system/audit-logs?tenant_id='.$globex->id)
            ->assertOk()
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonCount(2, 'items');
    }

    public function test_search_matches_action_and_actor_name(): void
    {
        $this->loginSuperAdmin();
        AuditLog::query()->delete();

        /** @var SystemUser $sa */
        $sa = SystemUser::where('email', 'superadmin@flowsync.test')->firstOrFail();

        $this->seedAudit(['action' => 'special.thing']);
        $this->seedAudit(['action' => 'ordinary.event', 'actor_id' => $sa->id]);

        $this->getJson('/api/system/audit-logs?q=special')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.action', 'special.thing');

        $this->getJson('/api/system/audit-logs?q='.urlencode($sa->name))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.action', 'ordinary.event');
    }

    public function test_export_streams_csv_and_audits_the_export(): void
    {
        $this->loginSuperAdmin();
        AuditLog::query()->delete();

        $this->seedAudit(['action' => 'exported.event', 'ip_address' => '10.0.0.1']);

        $response = $this->get('/api/system/audit-logs/export')->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $response->assertDownload();

        $body = (string) $response->streamedContent();
        $this->assertStringContainsString('created_at,type,action', $body);
        $this->assertStringContainsString('exported.event', $body);

        // The export writes its own trail row, naming actor + scope + count.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'audit.exported',
            'actor_id' => SystemUser::where('email', 'superadmin@flowsync.test')->value('id'),
        ]);
    }

    public function test_the_created_at_id_index_exists_on_the_system_database(): void
    {
        $this->assertTrue(
            Schema::connection('iso_system')->hasIndex('audit_logs', ['created_at', 'id']),
            'audit_logs needs a (created_at, id) index for the ordered feed union.'
        );
    }

    public function test_audit_exported_records_the_scope(): void
    {
        $this->loginSuperAdmin();
        AuditLog::query()->delete();

        $tenant = $this->globex();
        $this->seedAudit(['action' => 'globex.event', 'data' => ['tenant_id' => $tenant->id]]);
        $this->seedAudit(['action' => 'acme.event', 'data' => ['tenant_id' => $this->acme()->id]]);

        $this->get('/api/system/audit-logs/export?tenant_id='.$tenant->id)->assertOk();

        $exported = AuditLog::where('action', 'audit.exported')->firstOrFail();
        $this->assertSame('all', $exported->data['type']);
        $this->assertSame($tenant->id, (int) $exported->data['tenant_id']);
        $this->assertSame(1, (int) $exported->data['rows']);
    }
}
