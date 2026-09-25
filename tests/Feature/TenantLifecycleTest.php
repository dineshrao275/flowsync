<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\TenantLifecycle;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class TenantLifecycleTest extends TestCase
{
    use IsolatesDatabase;

    private TenantLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lifecycle = app(TenantLifecycle::class);
    }

    private static int $tenantCounter = 0;

    private function makeTenant(): Tenant
    {
        self::$tenantCounter++;

        return Tenant::create([
            'name' => 'Friendly Tenant #'.self::$tenantCounter,
            'slug' => 'friendly-'.self::$tenantCounter,
        ]);
    }

    public function test_new_tenant_defaults_to_active_and_provisioned(): void
    {
        $tenant = $this->makeTenant();

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertSame(Tenant::PROVISIONING_PROVISIONED, $tenant->provisioning_status);
        $this->assertTrue($tenant->isActive());
        $this->assertTrue($tenant->isServiceable());
        $this->assertTrue($tenant->isProvisioned());
    }

    public function test_service_has_complete_and_derived_transition_map(): void
    {
        $this->assertCount(8, TenantLifecycle::TRANSITIONS);

        foreach (TenantLifecycle::TRANSITIONS as $from => $toList) {
            foreach ($toList as $to) {
                $this->assertArrayHasKey($to, TenantLifecycle::TRANSITIONS, "Unknown target status {$to}");
            }
        }

        // Terminal status has no outgoing edges.
        $this->assertSame([], TenantLifecycle::TRANSITIONS[Tenant::STATUS_DEACTIVATED]);
    }

    public function test_allowed_transitions_are_accepted(): void
    {
        $scenarios = [
            [Tenant::STATUS_PENDING, Tenant::STATUS_PROVISIONING],
            [Tenant::STATUS_PROVISIONING, Tenant::STATUS_TRIAL],
            [Tenant::STATUS_PROVISIONING, Tenant::STATUS_ACTIVE],
            [Tenant::STATUS_PROVISIONING, Tenant::STATUS_PROVISIONING_FAILED],
            [Tenant::STATUS_PROVISIONING_FAILED, Tenant::STATUS_PROVISIONING],
            [Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE],
            [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED],
            [Tenant::STATUS_ACTIVE, Tenant::STATUS_EXPIRED],
            [Tenant::STATUS_SUSPENDED, Tenant::STATUS_ACTIVE],
            [Tenant::STATUS_EXPIRED, Tenant::STATUS_ACTIVE],
            [Tenant::STATUS_EXPIRED, Tenant::STATUS_DEACTIVATED],
        ];

        foreach ($scenarios as [$from, $to]) {
            $tenant = $this->makeTenant();
            $tenant->forceFill(['status' => $from])->save();

            $this->assertTrue($this->lifecycle->canTransition($tenant, $to), "{$from} → {$to} should be allowed");
        }
    }

    public function test_illegal_transitions_are_rejected(): void
    {
        $tenant = $this->makeTenant();
        $tenant->forceFill(['status' => Tenant::STATUS_DEACTIVATED])->save();

        $this->assertFalse($this->lifecycle->canTransition($tenant, Tenant::STATUS_ACTIVE));

        $this->expectException(ValidationException::class);
        $this->lifecycle->transition($tenant, Tenant::STATUS_ACTIVE);
    }

    public function test_transition_updates_status_and_writes_audit_log(): void
    {
        $tenant = $this->makeTenant();
        $actor = $this->systemUser('superadmin@flowsync.test');

        $this->lifecycle->transition($tenant, Tenant::STATUS_SUSPENDED, $actor, ['reason' => 'non-payment']);

        $tenant->refresh();
        $this->assertSame(Tenant::STATUS_SUSPENDED, $tenant->status);
        $this->assertFalse($tenant->isServiceable());

        $log = AuditLog::where('subject_type', Tenant::class)
            ->where('subject_id', $tenant->id)
            ->where('action', 'tenant.status_changed')
            ->firstOrFail();

        $this->assertSame(Tenant::STATUS_ACTIVE, $log->data['from']);
        $this->assertSame(Tenant::STATUS_SUSPENDED, $log->data['to']);
        $this->assertSame('non-payment', $log->data['reason']);
        $this->assertSame($actor->id, $log->actor_id);
    }

    public function test_entering_provisioning_resets_provisioning_error(): void
    {
        $tenant = $this->makeTenant();
        $tenant->forceFill([
            'status' => Tenant::STATUS_PROVISIONING_FAILED,
            'provisioning_status' => Tenant::PROVISIONING_FAILED,
            'provisioning_error' => 'CREATE DATABASE failed: blah',
        ])->save();

        $this->lifecycle->transition($tenant, Tenant::STATUS_PROVISIONING);

        $tenant->refresh();
        $this->assertSame(Tenant::STATUS_PROVISIONING, $tenant->status);
        $this->assertSame(Tenant::PROVISIONING_PENDING, $tenant->provisioning_status);
        $this->assertNull($tenant->provisioning_error);
    }

    public function test_reaching_serviceable_status_stamps_provisioned_at(): void
    {
        $tenant = $this->makeTenant();
        $tenant->forceFill(['status' => Tenant::STATUS_PENDING, 'provisioned_at' => null])->save();

        $this->lifecycle->transition($tenant, Tenant::STATUS_PROVISIONING);
        $this->lifecycle->transition($tenant, Tenant::STATUS_TRIAL);

        $tenant->refresh();
        $this->assertNotNull($tenant->provisioned_at);
    }

    public function test_transition_to_same_status_is_noop(): void
    {
        $tenant = $this->makeTenant();

        $result = $this->lifecycle->transition($tenant, Tenant::STATUS_ACTIVE);

        $this->assertSame($tenant->id, $result->id);
        $this->assertSame(0, AuditLog::where('subject_type', Tenant::class)
            ->where('subject_id', $tenant->id)->count());
    }
}
