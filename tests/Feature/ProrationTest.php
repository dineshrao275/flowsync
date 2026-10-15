<?php

namespace Tests\Feature;

use App\Billing\Gateways\FakePaymentGateway;
use App\Billing\PaymentResolver;
use App\Billing\Proration\ProrationCalculator;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P6.4 — a mid-period plan change credits the unused paid time (Stripe prorates by itself). */
class ProrationTest extends TestCase
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

    /** acme paid for `pro` through a one-off provider, halfway through the period. */
    private function halfwayThroughPro(): void
    {
        $subscription = app(SubscriptionService::class)->assign($this->acme(), $this->plan('pro'), ['billing_provider' => 'mock']);
        $subscription->update(['current_period_start' => now()->subDays(15), 'current_period_end' => now()->addDays(15)]);
    }

    public function test_the_credit_is_the_unused_share_of_what_was_paid(): void
    {
        $this->halfwayThroughPro();
        $current = $this->acme()->subscription;

        $credit = app(ProrationCalculator::class)->credit($current, $this->plan('business'));

        $this->assertEqualsWithDelta($this->plan('pro')->price_cents / 2, $credit['credit_cents'], 5);
        $this->assertEqualsWithDelta(0.5, $credit['ratio'], 0.01);
    }

    public function test_nothing_is_credited_for_a_trial_a_platform_assigned_plan_or_the_same_plan(): void
    {
        $calculator = app(ProrationCalculator::class);
        $this->halfwayThroughPro();
        $subscription = $this->acme()->subscription;

        $this->assertNull($calculator->credit($subscription, $this->plan('pro')));

        $subscription->update(['billing_provider' => null]);
        $this->assertNull($calculator->credit($subscription->refresh(), $this->plan('business')));

        $subscription->update(['billing_provider' => 'mock', 'status' => 'trialing']);
        $this->assertNull($calculator->credit($subscription->refresh(), $this->plan('business')));

        config(['billing.proration.enabled' => false]);
        $subscription->update(['status' => 'active']);
        $this->assertNull($calculator->credit($subscription->refresh(), $this->plan('business')));
    }

    public function test_a_one_off_provider_checkout_is_reduced_by_the_credit_and_invoiced_with_both_lines(): void
    {
        app(PaymentResolver::class)->swapFake(new FakePaymentGateway);
        $this->halfwayThroughPro();
        $business = $this->plan('business');
        $this->loginAs('admin@flowsync.test');

        $paymentId = $this->postJson('/api/billing/checkout', ['plan_id' => $business->id])->assertCreated()->json('payment.id');

        $payment = Payment::findOrFail($paymentId);
        $this->assertEqualsWithDelta($business->price_cents - $this->plan('pro')->price_cents / 2, $payment->amount_cents, 5);

        $this->postJson('/api/billing/verify', ['payment_id' => $paymentId, 'provider_payment_id' => 'pi_prorated'])->assertOk();

        $invoice = Invoice::with('lines')->where('payment_id', $paymentId)->firstOrFail();
        $this->assertSame(['subscription', 'proration'], $invoice->lines->pluck('kind')->all());
        $this->assertSame($payment->amount_cents, $invoice->total_cents);
        $this->assertLessThan(0, $invoice->lines->last()->amount_cents);
    }

    public function test_the_credit_never_takes_the_charge_below_the_floor(): void
    {
        app(PaymentResolver::class)->swapFake(new FakePaymentGateway);
        $this->halfwayThroughPro();
        $tenant = $this->acme();
        $tenant->subscription->update(['current_period_start' => now()->subDay(), 'current_period_end' => now()->addDay()->addMinute()]);
        $cheap = $this->plan('starter');
        $cheap->update(['price_cents' => 1000]);
        $quote = app(ProrationCalculator::class)->forCheckout($tenant->refresh(), $cheap, new FakePaymentGateway);

        $this->assertGreaterThanOrEqual(ProrationCalculator::MIN_CHARGE_CENTS, $quote['amount_cents']);
    }

    public function test_stripe_prorates_a_plan_change_itself(): void
    {
        config([
            'payments.driver' => 'stripe',
            'payments.gateways.stripe.secret' => 'sk_test_unit',
        ]);
        app()->forgetInstance(PaymentResolver::class);
        $tenant = $this->acme();
        app(SubscriptionService::class)->assign($tenant, $this->plan('pro'));
        $tenant->refresh()->subscription->update(['billing_provider' => 'stripe', 'provider_subscription_id' => 'sub_pr', 'status' => 'active']);
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_pr' => Http::response(['id' => 'sub_pr', 'items' => ['data' => [['id' => 'si_1']]]]),
            'api.stripe.com/v1/prices' => Http::response(['id' => 'price_b']),
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        ]);
        $this->loginAs('admin@flowsync.test');

        $this->postJson('/api/billing/checkout', ['plan_id' => $this->plan('business')->id])->assertOk()->assertJsonPath('changed', true);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/subscriptions/sub_pr')
            && ($r->data()['proration_behavior'] ?? null) === 'create_prorations');
        $this->assertSame(0, Payment::count());
    }
}
