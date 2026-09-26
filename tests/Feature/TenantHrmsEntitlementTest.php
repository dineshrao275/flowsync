<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P1.7 — super-admin per-tenant HRMS entitlement.
 *
 * Covers the additive override layer end to end: validation, the audit trail,
 * the operational log line (D2.17.6 — a state change writes both), and that a
 * tenant admin cannot reach the endpoint at all.
 */
class TenantHrmsEntitlementTest extends TestCase
{
    use IsolatesDatabase;

    private function loginSuperAdmin(): void
    {
        $this->actingAs($this->systemUser('superadmin@flowsync.test'), 'web');
    }

    private function hrmsUrl(Tenant $tenant, string $method = 'get'): string
    {
        return "/api/tenants/{$tenant->id}/hrms";
    }

    public function test_it_reports_the_unlimited_default_for_a_subscription_less_tenant(): void
    {
        $this->loginSuperAdmin();
        $acme = $this->acme();

        $this->getJson($this->hrmsUrl($acme))
            ->assertOk()
            ->assertJsonPath('hrms.enabled', true)
            ->assertJsonPath('hrms.source', 'none')
            ->assertJsonPath('hrms.modules', []);
    }

    public function test_source_is_plan_once_a_subscription_exists(): void
    {
        $this->loginSuperAdmin();
        $acme = $this->acme();

        $plan = app(SubscriptionPlan::class)->where('slug', 'starter')->firstOrFail();
        $acme->update(['limits_override' => ['modules' => ['hrms.core']]]);
        $acme->subscription()->create([
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->getJson($this->hrmsUrl($acme))
            ->assertOk()
            ->assertJsonPath('hrms.source', 'plan')
            ->assertJsonPath('hrms.effective_hrms', ['hrms.core']);
    }

    public function test_granting_a_module_writes_the_override_and_an_audit_row(): void
    {
        $this->loginSuperAdmin();
        $acme = $this->acme();

        $this->putJson($this->hrmsUrl($acme), ['modules' => ['hrms.payroll']])
            ->assertOk()
            ->assertJsonPath('hrms.modules', ['hrms.payroll' => true])
            ->assertJsonPath('hrms.source', 'override');

        $stored = data_get($acme->fresh()->features_override, 'modules');
        $this->assertSame(['hrms.payroll' => true], $stored);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.hrms_updated',
            'subject_type' => 'tenants',
            'subject_id' => $acme->id,
        ]);
    }

    public function test_unknown_modules_are_rejected(): void
    {
        $this->loginSuperAdmin();
        $acme = $this->acme();

        $this->putJson($this->hrmsUrl($acme), ['modules' => ['hrms.not_real']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modules.0');

        // A typo must not be persisted as an inert entitlement.
        $this->assertNull(data_get($acme->fresh()->features_override, 'modules'));
    }

    public function test_a_request_with_neither_input_is_rejected(): void
    {
        $this->loginSuperAdmin();
        $acme = $this->acme();

        $this->putJson($this->hrmsUrl($acme), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('form');
    }

    public function test_enabled_false_revokes_every_hrms_module_but_keeps_other_overrides(): void
    {
        $this->loginSuperAdmin();
        $acme = $this->acme();

        $acme->update([
            'features_override' => ['modules' => ['reports' => true, 'hrms.core' => true]],
        ]);

        $this->putJson($this->hrmsUrl($acme), ['enabled' => false])->assertOk();

        $modules = data_get($acme->fresh()->features_override, 'modules');

        $this->assertTrue($modules['reports'], 'A non-HRMS override must survive');
        $this->assertFalse($modules['hrms.core']);
        $this->assertFalse($modules['hrms.payroll'], 'Every catalog hrms key is revoked');
    }

    public function test_enabled_true_grants_every_hrms_module(): void
    {
        $this->loginSuperAdmin();
        $acme = $this->acme();

        $acme->update(['features_override' => ['modules' => ['hrms.core' => false]]]);

        $this->putJson($this->hrmsUrl($acme), ['enabled' => true])->assertOk();

        $modules = data_get($acme->fresh()->features_override, 'modules');

        $this->assertTrue($modules['hrms.core']);
        $this->assertTrue($modules['hrms.payroll.statutory']);

        foreach (array_keys($modules) as $module) {
            $this->assertStringStartsWith('hrms.', $module);
        }
    }

    public function test_setting_modules_leaves_non_hrms_overrides_alone(): void
    {
        $this->loginSuperAdmin();
        $acme = $this->acme();

        $acme->update(['features_override' => ['modules' => ['reports' => false, 'hrms.core' => false]]]);

        $this->putJson($this->hrmsUrl($acme), ['modules' => ['hrms.payroll']])->assertOk();

        $modules = data_get($acme->fresh()->features_override, 'modules');

        $this->assertFalse($modules['reports']);
        $this->assertTrue($modules['hrms.payroll']);
        $this->assertArrayNotHasKey('hrms.core', $modules, 'A replaced checklist drops stale HRMS keys');
    }

    public function test_get_reflects_a_previous_write(): void
    {
        $this->loginSuperAdmin();
        $acme = $this->acme();

        $this->putJson($this->hrmsUrl($acme), ['modules' => ['hrms.talent']])->assertOk();

        $this->getJson($this->hrmsUrl($acme))
            ->assertOk()
            ->assertJsonPath('hrms.modules', ['hrms.talent' => true])
            ->assertJsonFragment(['hrms.talent']);
    }

    public function test_the_change_is_logged_to_the_hrms_channel_with_the_event_name(): void
    {
        $context = [];

        // `Log::spy()` returns null from channel(), so assert against a channel
        // double instead: the hrms channel resolves to a spy that records the
        // call, every other channel resolves to a no-op so the rest of the
        // request keeps working.
        $channel = \Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('info')
            ->once()
            ->with('tenant.hrms.updated', \Mockery::capture($context));

        Log::shouldReceive('channel')
            ->with('hrms')
            ->andReturn($channel);
        Log::shouldReceive('channel')
            ->with(\Mockery::not('hrms'))
            ->andReturnSelf();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();

        $this->loginSuperAdmin();
        $acme = $this->acme();

        $this->putJson($this->hrmsUrl($acme), ['modules' => ['hrms.payroll']])->assertOk();

        $this->assertSame($acme->id, $context['tenant_id']);
        $this->assertSame('modules', $context['via']);
        $this->assertContains('hrms.payroll', $context['granted']);

        // D2.17.8: catalog slugs only — never a value that would be unsafe in
        // a support ticket.
        foreach (['salary', 'bank', 'pan', 'payload', 'password', 'token'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $context);
        }
    }

    public function test_a_tenant_admin_cannot_touch_another_tenants_entitlement(): void
    {
        $this->loginAs('admin@flowsync.test');
        $acme = $this->acme();

        $this->getJson($this->hrmsUrl($acme))->assertForbidden();
        $this->putJson($this->hrmsUrl($acme), ['enabled' => true])->assertForbidden();
    }

    public function test_the_response_carries_the_display_tree(): void
    {
        $this->loginSuperAdmin();

        $this->getJson($this->hrmsUrl($this->acme()))
            ->assertOk()
            ->assertJsonStructure([
                'hrms' => [
                    'enabled', 'source', 'modules', 'effective_modules', 'effective_hrms',
                    'groups' => [['key', 'label', 'modules' => [['key', 'label', 'depth']]]],
                ],
            ]);
    }

    public function test_a_tenant_without_a_subscription_stays_unlimited_after_an_override(): void
    {
        $this->loginSuperAdmin();
        $acme = $this->acme();

        // Revoking one module on an unlimited tenant must not switch the rest off.
        $this->putJson($this->hrmsUrl($acme), ['modules' => ['hrms.core']])->assertOk();
        $acme->update([
            'features_override' => ['modules' => ['hrms.core' => false]],
        ]);

        $this->getJson($this->hrmsUrl($acme))
            ->assertOk()
            ->assertJsonPath('hrms.enabled', true)
            ->assertJsonFragment(['hrms.payroll']);
    }
}
