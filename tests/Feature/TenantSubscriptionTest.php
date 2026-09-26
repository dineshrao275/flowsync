<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use Carbon\Carbon;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class TenantSubscriptionTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_showing_a_subscription_returns_payload_with_plans(): void
    {
        $this->getJson("/api/tenants/{$this->acme()->id}/subscription")
            ->assertOk()
            ->assertJsonPath('subscription', null)
            ->assertJsonCount(count(config('subscriptions.plans')), 'plans');
    }

    public function test_assign_creates_and_re_stamps_the_single_subscription_row(): void
    {
        $starter = SubscriptionPlan::where('slug', 'starter')->firstOrFail();
        $pro = SubscriptionPlan::where('slug', 'pro')->firstOrFail();

        $this->postJson("/api/tenants/{$this->acme()->id}/subscription", [
            'plan_id' => $starter->id,
            'auto_renew' => true,
        ])->assertOk()
            ->assertJsonPath('subscription.status', Subscription::STATUS_ACTIVE)
            ->assertJsonPath('subscription.plan.slug', 'starter');

        $this->assertSame(1, Subscription::where('tenant_id', $this->acme()->id)->count());

        // Reassign to another plan re-stamps the same row + records plan_changed.
        $this->postJson("/api/tenants/{$this->acme()->id}/subscription", [
            'plan_id' => $pro->id,
        ])->assertOk()->assertJsonPath('subscription.plan.slug', 'pro');

        $this->assertSame(1, Subscription::where('tenant_id', $this->acme()->id)->count());

        $types = SubscriptionEvent::where('tenant_id', $this->acme()->id)
            ->orderBy('id')->pluck('type')->all();
        $this->assertSame([Subscription::EVENT_SUBSCRIBED, Subscription::EVENT_PLAN_CHANGED], $types);
    }

    public function test_trial_marks_tenant_and_subscription_trialing(): void
    {
        $starter = SubscriptionPlan::where('slug', 'starter')->firstOrFail();

        $this->postJson("/api/tenants/{$this->acme()->id}/subscription/trial", [
            'plan_id' => $starter->id,
            'days' => 21,
        ])->assertOk()
            ->assertJsonPath('subscription.status', Subscription::STATUS_TRIALING);

        // Seas the tenant lifecycle too.
        $this->acme()->refresh();
        $this->assertSame('trial', $this->acme()->status);
        $this->assertEqualsWithDelta(
            Carbon::now()->addDays(21)->startOfDay()->timestamp,
            $this->acme()->trial_ends_at->startOfDay()->timestamp,
            3600
        );

        $event = SubscriptionEvent::where('tenant_id', $this->acme()->id)->firstOrFail();
        $this->assertSame(Subscription::EVENT_TRIAL_STARTED, $event->type);
        $this->assertSame(21, $event->data['days']);
    }

    public function test_cancel_and_renew_cycle(): void
    {
        $starter = SubscriptionPlan::where('slug', 'starter')->firstOrFail();
        $this->postJson("/api/tenants/{$this->acme()->id}/subscription", [
            'plan_id' => $starter->id,
        ])->assertOk();

        $this->postJson("/api/tenants/{$this->acme()->id}/subscription/cancel")
            ->assertOk()
            ->assertJsonPath('subscription.status', Subscription::STATUS_CANCELED)
            ->assertJsonPath('subscription.auto_renew', false);

        $sub = $this->acme()->subscription;
        $this->assertNotNull($sub->canceled_at);

        $this->postJson("/api/tenants/{$this->acme()->id}/subscription/renew")
            ->assertOk()
            ->assertJsonPath('subscription.status', Subscription::STATUS_ACTIVE)
            ->assertJsonPath('subscription.auto_renew', true);

        $sub->refresh();
        $this->assertNull($sub->canceled_at);

        $types = SubscriptionEvent::where('tenant_id', $this->acme()->id)
            ->orderBy('id')->pluck('type')->all();
        $this->assertSame([
            Subscription::EVENT_SUBSCRIBED,
            Subscription::EVENT_CANCELED,
            Subscription::EVENT_REACTIVATED,
        ], $types);
    }

    public function test_suspend_marks_subscription_past_due(): void
    {
        $starter = SubscriptionPlan::where('slug', 'starter')->firstOrFail();
        $this->postJson("/api/tenants/{$this->acme()->id}/subscription", [
            'plan_id' => $starter->id,
        ])->assertOk();

        $this->postJson("/api/tenants/{$this->acme()->id}/subscription/suspend", [
            'reason' => 'Card declined.',
        ])->assertOk()->assertJsonPath('subscription.status', Subscription::STATUS_PAST_DUE);

        $event = SubscriptionEvent::where('tenant_id', $this->acme()->id)
            ->where('type', Subscription::EVENT_PAUSED)
            ->firstOrFail();
        $this->assertSame('Card declined.', $event->data['reason']);
    }

    public function test_events_endpoint_lists_audit_history(): void
    {
        $starter = SubscriptionPlan::where('slug', 'starter')->firstOrFail();
        $pro = SubscriptionPlan::where('slug', 'pro')->firstOrFail();

        $this->postJson("/api/tenants/{$this->acme()->id}/subscription", ['plan_id' => $starter->id])->assertOk();
        $this->postJson("/api/tenants/{$this->acme()->id}/subscription", ['plan_id' => $pro->id])->assertOk();
        $this->postJson("/api/tenants/{$this->acme()->id}/subscription/cancel")->assertOk();

        $response = $this->getJson("/api/tenants/{$this->acme()->id}/subscription/events")
            ->assertOk()
            ->assertJsonCount(3, 'events.data');

        $types = collect($response->json('events.data'))->pluck('type');
        $this->assertSame(
            [Subscription::EVENT_CANCELED, Subscription::EVENT_PLAN_CHANGED, Subscription::EVENT_SUBSCRIBED],
            $types->all()
        );

        // Plan change event carries from/to slugs.
        $planChanged = collect($response->json('events.data'))->firstWhere('type', Subscription::EVENT_PLAN_CHANGED);
        $this->assertSame('pro', $planChanged['to_plan']['slug']);
    }

    public function test_tenant_admin_cannot_manage_subscriptions(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->getJson("/api/tenants/{$this->acme()->id}/subscription")->assertForbidden();
        $this->postJson("/api/tenants/{$this->acme()->id}/subscription", ['plan_id' => 1])->assertForbidden();
    }
}
