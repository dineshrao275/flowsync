<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** FB-5 — the Super Admin overview numbers and the extra tenant filters. */
class TenantSummaryTest extends TestCase
{
    use IsolatesDatabase;

    private function sa(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'superadmin@flowsync.test', 'password' => 'password'])->assertOk();
    }

    public function test_summary_counts_enabled_disabled_subscriptions_and_mrr(): void
    {
        $acme = Tenant::where('slug', 'acme')->firstOrFail();
        $globex = Tenant::where('slug', 'globex')->firstOrFail();
        $pro = SubscriptionPlan::where('slug', 'pro')->firstOrFail();
        app(SubscriptionService::class)->assign($acme, $pro);
        $acme->refresh()->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'auto_renew' => true]);
        $globex->update(['status' => Tenant::STATUS_SUSPENDED]);
        Tenant::create(['name' => 'Half Done', 'slug' => 'half-done', 'status' => Tenant::STATUS_DRAFT, 'provisioning_status' => 'pending']);

        $this->sa();
        $summary = $this->getJson('/api/tenants/summary')->assertOk()->json('summary');

        $this->assertSame(3, $summary['tenants']['total']);
        $this->assertSame(1, $summary['tenants']['disabled']);          // globex
        $this->assertSame(1, $summary['tenants']['by_status']['draft']); // not enabled, not disabled
        $this->assertSame(1, $summary['subscriptions']['by_status']['active']);
        $this->assertSame((int) $pro->price_cents, $summary['mrr_cents']);
        $this->assertContains('Setup incomplete', array_column($summary['attention'], 'reason'));
    }

    public function test_a_past_due_subscription_is_flagged_and_filterable(): void
    {
        $acme = Tenant::where('slug', 'acme')->firstOrFail();
        app(SubscriptionService::class)->assign($acme, SubscriptionPlan::where('slug', 'pro')->firstOrFail());
        $acme->refresh()->subscription->update(['status' => Subscription::STATUS_PAST_DUE]);

        $this->sa();
        $this->assertContains('Payment past due', array_column($this->getJson('/api/tenants/summary')->json('summary.attention'), 'reason'));

        $slugs = array_column($this->getJson('/api/tenants?subscription_status=past_due')->assertOk()->json('tenants'), 'slug');
        $this->assertSame(['acme'], $slugs);

        $none = array_column($this->getJson('/api/tenants?subscription_status=none')->json('tenants'), 'slug');
        $this->assertNotContains('acme', $none);
    }

    public function test_summary_is_super_admin_only(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();

        $this->getJson('/api/tenants/summary')->assertForbidden();
    }
}
