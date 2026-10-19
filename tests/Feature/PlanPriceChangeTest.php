<?php

namespace Tests\Feature;

use App\Billing\PriceChanges\PlanPriceChanges;
use App\Mail\BillingNoticeMail;
use App\Models\PlanPriceChange;
use App\Models\Subscription;
use App\Models\SubscriptionPriceNotice;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\StripeTestHelpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** A price edit reaches existing subscribers only with notice: email first, new amount from a renewal at least 30 days away. */
class PlanPriceChangeTest extends TestCase
{
    use IsolatesDatabase;
    use StripeTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStripe();
        Mail::fake();
        Http::fake([
            'api.stripe.com/v1/customers*' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/products*' => Http::response(['id' => 'prod_1']),
            'api.stripe.com/v1/prices/*' => Http::response(['id' => 'price_old']),
            'api.stripe.com/v1/prices' => Http::response(['id' => 'price_new']),
            'api.stripe.com/v1/subscriptions/sub_123' => Http::response(['id' => 'sub_123', 'items' => ['data' => [['id' => 'si_1']]]]),
        ]);
    }

    private function edit(int $priceCents, bool $confirm = false)
    {
        $plan = $this->plan('pro');

        return $this->putJson("/api/plans/{$plan->id}", [
            'name' => $plan->name, 'slug' => $plan->slug, 'product' => $plan->product, 'billing_cycle' => 'monthly',
            'price_cents' => $priceCents, 'limits' => $plan->limits, 'is_active' => true,
            'confirm_price_change' => $confirm,
        ]);
    }

    public function test_an_increase_needs_confirmation_when_paying_subscribers_exist_and_changes_nothing_until_then(): void
    {
        $this->stripeSubscriber('pro');
        $this->loginSuperAdmin();

        $this->edit(3900)->assertStatus(409)
            ->assertJsonPath('code', 'price_change_confirmation_required')
            ->assertJsonPath('affected_subscriptions', 1)
            ->assertJsonPath('notice_days', 30);

        $this->assertSame(2900, $this->plan('pro')->price_cents);
        $this->assertSame(0, PlanPriceChange::count());
        Mail::assertNothingQueued();
    }

    public function test_a_confirmed_increase_emails_the_subscriber_the_new_amount_and_a_start_date_at_least_notice_days_away(): void
    {
        $tenant = $this->stripeSubscriber('pro', daysLeft: 20);
        $this->loginSuperAdmin();

        $this->edit(3900, confirm: true)->assertOk();

        $this->assertSame(3900, $this->plan('pro')->price_cents); // new customers pay it at once

        $notice = SubscriptionPriceNotice::firstOrFail();
        $this->assertSame(2900, $notice->old_amount_cents);
        $this->assertSame(3900, $notice->new_amount_cents);
        $this->assertSame(SubscriptionPriceNotice::PENDING, $notice->status);
        // 20 days left < 30 days notice, so the first renewal that is far enough away is the one after: +1 month.
        $this->assertTrue($notice->effective_at->gte(now()->addDays(30)));
        $this->assertTrue($notice->effective_at->isSameDay($tenant->subscription->current_period_end->copy()->addMonth()));
        $this->assertNotNull($notice->notified_at);

        Mail::assertQueued(BillingNoticeMail::class, function (BillingNoticeMail $m) use ($notice) {
            return $m->hasTo('billing@acme.test')
                && str_contains($m->mailSubject, 'Pro')
                && str_contains(implode(' ', $m->lines), 'USD 39.00')
                && str_contains(implode(' ', $m->lines), 'USD 29.00')
                && str_contains(implode(' ', $m->lines), $notice->effective_at->format('Y'));
        });
        // also the tenant's admins
        Mail::assertQueued(BillingNoticeMail::class, fn (BillingNoticeMail $m) => $m->hasTo('admin@flowsync.test'));
    }

    public function test_a_decrease_applies_from_the_next_renewal_with_no_minimum_notice(): void
    {
        $tenant = $this->stripeSubscriber('pro', daysLeft: 5);
        $this->loginSuperAdmin();

        $this->edit(1900)->assertOk(); // decreases need no confirmation

        $notice = SubscriptionPriceNotice::firstOrFail();
        $this->assertTrue($notice->effective_at->isSameDay($tenant->subscription->current_period_end));
        Mail::assertQueued(BillingNoticeMail::class, fn (BillingNoticeMail $m) => str_contains(implode(' ', $m->lines), 'going down'));
    }

    public function test_no_paying_subscribers_means_no_confirmation_and_no_notice(): void
    {
        $this->loginSuperAdmin();

        $this->edit(4900)->assertOk();

        $this->assertSame(4900, $this->plan('pro')->price_cents);
        $this->assertSame(0, SubscriptionPriceNotice::count());
        Mail::assertNothingQueued();
    }

    public function test_the_provider_subscription_moves_to_the_new_price_only_shortly_before_the_renewal_without_prorating(): void
    {
        $this->stripeSubscriber('pro', daysLeft: 20);
        $this->loginSuperAdmin();
        $this->edit(3900, confirm: true)->assertOk();
        $notice = SubscriptionPriceNotice::firstOrFail();
        $changes = app(PlanPriceChanges::class);

        // today: nothing to do, Stripe untouched
        $this->assertSame(['applied' => 0, 'cancelled' => 0, 'failed' => 0], $changes->apply());
        $this->assertCount(0, $this->stripePosts('/subscriptions/sub_123'));

        // the day before the renewal it is applied, once, with no proration and no charge now
        $result = $changes->apply($notice->effective_at->copy()->subHours(12));
        $this->assertSame(1, $result['applied']);
        $posts = $this->stripePosts('/subscriptions/sub_123');
        $this->assertCount(1, $posts);
        $this->assertSame('none', $posts[0]->data()['proration_behavior']);
        $this->assertSame('price_new', $posts[0]->data()['items[0][price]']);
        $this->assertSame(SubscriptionPriceNotice::APPLIED, $notice->fresh()->status);

        // idempotent
        $this->assertSame(0, $changes->apply($notice->effective_at)['applied']);
        $this->assertCount(1, $this->stripePosts('/subscriptions/sub_123'));
    }

    public function test_a_cancelled_subscriber_is_not_moved_to_a_price_it_will_never_pay(): void
    {
        $tenant = $this->stripeSubscriber('pro');
        $this->loginSuperAdmin();
        $this->edit(3900, confirm: true)->assertOk();

        $tenant->subscription->update(['status' => Subscription::STATUS_CANCELED, 'auto_renew' => false]);
        $result = app(PlanPriceChanges::class)->apply(now()->addYear());

        $this->assertSame(1, $result['cancelled']);
        $this->assertCount(0, $this->stripePosts('/subscriptions/sub_123'));
    }

    public function test_a_second_change_supersedes_the_pending_notice_and_keeps_what_the_subscriber_pays_today(): void
    {
        $this->stripeSubscriber('pro');
        $this->loginSuperAdmin();
        $this->edit(3900, confirm: true)->assertOk();
        $this->edit(4500, confirm: true)->assertOk();

        $notices = SubscriptionPriceNotice::orderBy('id')->get();
        $this->assertSame([SubscriptionPriceNotice::CANCELLED, SubscriptionPriceNotice::PENDING], $notices->pluck('status')->all());
        $this->assertSame(2900, $notices[1]->old_amount_cents); // still paying the original amount
        $this->assertSame(4500, $notices[1]->new_amount_cents);
    }

    public function test_a_trial_without_a_card_and_a_platform_assigned_plan_are_not_notified(): void
    {
        $tenant = $this->acme();
        // platform-assigned (nobody bills it) on the plan being repriced
        app(SubscriptionService::class)->assign($tenant, $this->plan('pro'));
        $this->loginSuperAdmin();

        $this->edit(3900)->assertOk(); // no confirmation: it reaches nobody who pays

        $this->assertSame(0, SubscriptionPriceNotice::count());
        Mail::assertNothingQueued();
    }
}
