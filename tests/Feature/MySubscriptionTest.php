<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class MySubscriptionTest extends TestCase
{
    use IsolatesDatabase;

    private function acme(): Tenant
    {
        return Tenant::where('slug', 'acme')->firstOrFail();
    }

    private function plan(string $slug): SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', $slug)->firstOrFail();
    }

    private function assign(Tenant $tenant, SubscriptionPlan $plan): void
    {
        app(SubscriptionService::class)->assign($tenant, $plan);
    }

    public function test_tenant_admin_reads_own_subscription(): void
    {
        $acme = $this->acme();
        $this->assign($acme, $this->plan('pro'));

        $this->loginAs('admin@flowsync.test');

        $this->getJson('/api/my-subscription')
            ->assertOk()
            ->assertJsonPath('tenant.id', $acme->id)
            ->assertJsonPath('tenant.slug', 'acme')
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('subscription.plan.slug', 'pro')
            ->assertJsonPath('subscription.plan.limits.users', 50)
            ->assertJsonPath('subscription.auto_renew', true);
    }

    public function test_tenant_without_subscription_gets_null_payload(): void
    {
        $acme = $this->acme();
        $acme->subscription?->delete();
        $acme->fresh();

        $this->loginAs('admin@flowsync.test');

        $this->getJson('/api/my-subscription')
            ->assertOk()
            ->assertJsonPath('subscription', null)
            ->assertJsonPath('tenant.name', 'Acme Corp')
            ->assertJsonPath('events', []);
    }

    public function test_usage_reports_counts_and_effective_limits(): void
    {
        $this->assign($this->acme(), $this->plan('starter'));

        $this->loginAs('admin@flowsync.test');

        $this->getJson('/api/my-usage')
            ->assertOk()
            ->assertJsonPath('usage.users', User::count())
            ->assertJsonPath('limits.users', 5)
            ->assertJsonPath('limits.projects', 10)
            ->assertJsonFragment(['modules' => ['time_tracking']]);
    }

    public function test_plans_catalog_is_active_only_for_tenants_but_full_for_super_admin(): void
    {
        $starter = $this->plan('starter');
        $starter->update(['is_active' => false]);

        $this->loginAs('admin@flowsync.test');
        $this->getJson('/api/plans')
            ->assertOk()
            ->assertJsonCount(2, 'plans')
            ->assertJsonMissing(['plans' => [['slug' => 'starter']]]);

        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/plans')
            ->assertOk()
            ->assertJsonCount(3, 'plans');
    }

    public function test_admin_can_switch_cancel_and_renew(): void
    {
        $acme = $this->acme();
        $this->assign($acme, $this->plan('starter'));

        $this->loginAs('admin@flowsync.test');

        $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('pro')->id])
            ->assertOk()
            ->assertJsonPath('message', 'Plan updated.')
            ->assertJsonPath('subscription.plan.slug', 'pro');

        $this->assertDatabaseHas('subscription_events', [
            'type' => 'plan_changed',
            'from_plan_id' => $this->plan('starter')->id,
            'to_plan_id' => $this->plan('pro')->id,
        ], 'iso_system');

        // Switching to the same plan is a no-op.
        $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('pro')->id])
            ->assertOk()
            ->assertJsonPath('message', 'Already subscribed to this plan.');

        $this->postJson('/api/my-subscription/cancel')
            ->assertOk()
            ->assertJsonPath('message', 'Subscription canceled.')
            ->assertJsonPath('subscription.status', 'canceled')
            ->assertJsonPath('subscription.auto_renew', false);

        $this->postJson('/api/my-subscription/renew')
            ->assertOk()
            ->assertJsonPath('message', 'Subscription renewed.')
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('subscription.auto_renew', true);
    }

    public function test_switch_to_inactive_plan_is_rejected(): void
    {
        $acme = $this->acme();
        $this->assign($acme, $this->plan('starter'));
        $this->plan('pro')->update(['is_active' => false]);

        $this->loginAs('admin@flowsync.test');

        $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('pro')->id])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_manage_subscription(): void
    {
        $acme = $this->acme();
        $this->assign($acme, $this->plan('starter'));

        $this->loginAs('editor@flowsync.test');

        // Read is open to every tenant user…
        $this->getJson('/api/my-subscription')->assertOk();
        $this->getJson('/api/my-usage')->assertOk();

        // …but mutations are admin-only.
        $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('pro')->id])->assertForbidden();
        $this->postJson('/api/my-subscription/cancel')->assertForbidden();
        $this->postJson('/api/my-subscription/renew')->assertForbidden();
    }

    public function test_super_admin_has_no_tenant_context_for_self_service(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/my-subscription')->assertNotFound();
        $this->getJson('/api/my-usage')->assertNotFound();
    }
}
