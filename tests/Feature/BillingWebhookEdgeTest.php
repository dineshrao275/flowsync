<?php

namespace Tests\Feature;

use App\Mail\BillingNoticeMail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\StripeTestHelpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Webhook-driven sync under real-world noise: duplicates, retries, out-of-order delivery, bad signatures, card trouble. */
class BillingWebhookEdgeTest extends TestCase
{
    use IsolatesDatabase;
    use StripeTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStripe();
        Mail::fake();
    }

    private function invoice(string $id, string $subId, int $periodEnd, string $type = 'invoice.paid', array $extra = []): array
    {
        return [
            'id' => 'evt_'.$id.'_'.$type, 'type' => $type,
            'data' => ['object' => array_merge([
                'id' => $id, 'subscription' => $subId, 'billing_reason' => 'subscription_cycle', 'amount_paid' => 2900, 'amount_due' => 2900, 'currency' => 'usd',
                'payment_intent' => 'pi_'.$id, 'lines' => ['data' => [['period' => ['start' => $periodEnd - 2592000, 'end' => $periodEnd], 'amount' => 2900]]],
            ], $extra)],
        ];
    }

    public function test_bad_and_stale_signatures_are_rejected(): void
    {
        $this->stripeSubscriber();
        $body = json_encode($this->invoice('in_1', 'sub_123', now()->addMonth()->timestamp));

        $this->call('POST', '/api/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=deadbeef', 'CONTENT_TYPE' => 'application/json'], $body)->assertForbidden();
        $this->call('POST', '/api/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertForbidden();

        $old = time() - 3600;
        $this->call('POST', '/api/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$old},v1=".hash_hmac('sha256', "{$old}.{$body}", self::WEBHOOK_SECRET), 'CONTENT_TYPE' => 'application/json'], $body)->assertForbidden();

        $this->assertSame(0, Payment::count());
    }

    public function test_one_invoice_announced_twice_under_different_event_ids_renews_once(): void
    {
        $tenant = $this->stripeSubscriber();
        $end = now()->addDays(50)->startOfSecond()->timestamp;

        $this->stripeWebhook($this->invoice('in_dup', 'sub_123', $end, 'invoice.paid'))->assertOk();
        $this->stripeWebhook($this->invoice('in_dup', 'sub_123', $end, 'invoice.payment_succeeded'))->assertOk();

        $this->assertSame(1, Payment::where('provider_order_id', 'in_dup')->count());
        $this->assertSame(1, Invoice::where('provider_invoice_id', 'in_dup')->count());
        $this->assertSame(1, SubscriptionEvent::where('subscription_id', $tenant->subscription->id)->where('type', 'renewed')->count());
        $this->assertSame($end, $tenant->subscription->refresh()->current_period_end->timestamp);
    }

    public function test_a_second_delivery_of_the_same_event_while_the_first_is_processing_is_acknowledged_not_repeated(): void
    {
        $this->stripeSubscriber();
        $event = $this->invoice('in_race', 'sub_123', now()->addDays(50)->timestamp);

        $lock = Cache::lock('billing:event:'.$event['id'], 30);
        $this->assertTrue($lock->get());
        try {
            $this->stripeWebhook($event)->assertOk()->assertJsonPath('status', 'in_progress');
            $this->assertSame(0, Payment::count());
        } finally {
            $lock->release();
        }

        $this->stripeWebhook($event)->assertOk()->assertJsonPath('status', 'processed');
        $this->stripeWebhook($event)->assertOk()->assertJsonPath('status', 'already_processed');
        $this->assertSame(1, Payment::count());
    }

    public function test_an_older_invoice_arriving_late_never_shrinks_the_period(): void
    {
        $tenant = $this->stripeSubscriber('pro', daysLeft: 5);
        $newer = now()->addDays(60)->startOfSecond()->timestamp;
        $older = now()->addDays(25)->startOfSecond()->timestamp;

        $this->stripeWebhook($this->invoice('in_new', 'sub_123', $newer))->assertOk();
        $this->stripeWebhook($this->invoice('in_old', 'sub_123', $older))->assertOk();

        $this->assertSame($newer, $tenant->subscription->refresh()->current_period_end->timestamp);
        $this->assertSame(2, Payment::count()); // both payments are history
        $this->assertSame(1, SubscriptionEvent::where('type', 'renewed')->count()); // only the newer one renewed
    }

    public function test_the_provider_receipt_and_invoice_links_reach_the_billing_history(): void
    {
        $this->stripeSubscriber();
        $this->stripeWebhook($this->invoice('in_rcpt', 'sub_123', now()->addDays(50)->timestamp, 'invoice.paid', [
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct/in_rcpt', 'invoice_pdf' => 'https://pay.stripe.com/invoice/in_rcpt/pdf', 'receipt_number' => '1234-5678',
        ]))->assertOk();

        $this->loginAdmin();
        $this->getJson('/api/billing/invoices')->assertOk()
            ->assertJsonPath('invoices.0.hosted_invoice_url', 'https://invoice.stripe.com/i/acct/in_rcpt')
            ->assertJsonPath('invoices.0.provider_pdf_url', 'https://pay.stripe.com/invoice/in_rcpt/pdf')
            ->assertJsonPath('invoices.0.receipt_number', '1234-5678');
    }

    public function test_a_failed_charge_flags_the_subscription_and_a_later_payment_heals_it(): void
    {
        $tenant = $this->stripeSubscriber();

        $this->stripeWebhook($this->invoice('in_fail', 'sub_123', now()->addDays(50)->timestamp, 'invoice.payment_failed', ['last_finalization_error' => ['message' => 'Your card has expired.']]))->assertOk();
        $this->assertSame(Subscription::STATUS_PAST_DUE, $tenant->subscription->refresh()->status);
        $paused = SubscriptionEvent::where('type', 'paused')->first();
        $this->assertSame('Your card has expired.', $paused->data['failure_reason']);

        // Stripe's retry succeeds after the customer updates the card
        $this->stripeWebhook($this->invoice('in_retry', 'sub_123', now()->addDays(55)->timestamp))->assertOk();
        $subscription = $tenant->subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->past_due_at);
    }

    public function test_the_customer_is_asked_to_confirm_a_payment_the_bank_challenged(): void
    {
        $this->stripeSubscriber();
        $this->stripeWebhook($this->invoice('in_sca', 'sub_123', now()->addDays(50)->timestamp, 'invoice.payment_action_required'))->assertOk();

        Mail::assertQueued(BillingNoticeMail::class, fn (BillingNoticeMail $m) => $m->hasTo('billing@acme.test') && str_contains($m->mailSubject, 'Action needed'));
    }

    public function test_a_card_trial_that_is_about_to_convert_is_announced(): void
    {
        $this->stripeSubscriber();
        $this->stripeWebhook([
            'id' => 'evt_trial', 'type' => 'customer.subscription.trial_will_end',
            'data' => ['object' => ['id' => 'sub_123', 'customer' => 'cus_1', 'trial_end' => now()->addDays(3)->timestamp, 'metadata' => []]],
        ])->assertOk();

        Mail::assertQueued(BillingNoticeMail::class, fn (BillingNoticeMail $m) => $m->hasTo('billing@acme.test') && str_contains($m->mailSubject, 'trial ends'));
    }

    public function test_a_subscription_cancelled_at_stripe_keeps_access_until_the_paid_period_ends(): void
    {
        $tenant = $this->stripeSubscriber('pro', daysLeft: 9);

        $this->stripeWebhook(['id' => 'evt_del', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_123', 'customer' => 'cus_1', 'metadata' => []]]])->assertOk();

        $subscription = $tenant->subscription->refresh();
        $this->assertSame(Subscription::STATUS_CANCELED, $subscription->status);
        $this->assertFalse($subscription->auto_renew);
        $this->assertTrue($subscription->current_period_end->isFuture());
        $this->assertSame('active', $tenant->refresh()->status);
    }

    public function test_a_refund_made_at_stripe_marks_the_payment_refunded(): void
    {
        $tenant = $this->stripeSubscriber();
        $payment = Payment::create(['tenant_id' => $tenant->id, 'subscription_id' => $tenant->subscription->id, 'provider' => 'stripe', 'provider_payment_id' => 'pi_ref', 'provider_order_id' => 'cs_ref', 'amount_cents' => 2900, 'currency' => 'usd', 'status' => Payment::STATUS_COMPLETED, 'idempotency_key' => 'k_ref']);

        $this->stripeWebhook(['id' => 'evt_ref', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_1', 'payment_intent' => 'pi_ref', 'amount' => 2900, 'currency' => 'usd', 'metadata' => []]]])->assertOk();

        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertSame(1, PaymentEvent::where('provider_event_id', 'evt_ref')->count());
    }

    public function test_events_for_unknown_objects_are_acknowledged_without_side_effects(): void
    {
        $this->stripeWebhook($this->invoice('in_x', 'sub_nobody', now()->addDays(50)->timestamp))->assertOk()->assertJsonPath('status', 'subscription_not_found');
        $this->stripeWebhook(['id' => 'evt_odd', 'type' => 'balance.available', 'data' => ['object' => []]])->assertOk()->assertJsonPath('status', 'ignored');

        $this->assertSame(0, Payment::count());
    }

    public function test_a_cancelled_subscription_can_be_resumed_before_the_period_ends(): void
    {
        $tenant = $this->stripeSubscriber('pro', daysLeft: 9);
        Http::fake(['api.stripe.com/v1/subscriptions/sub_123' => Http::response(['id' => 'sub_123'])]);
        $this->loginAdmin();

        $this->postJson('/api/my-subscription/cancel')->assertOk();
        $this->assertSame(Subscription::STATUS_CANCELED, $tenant->subscription->refresh()->status);

        $this->postJson('/api/my-subscription/renew')->assertOk();
        $resumed = collect($this->stripePosts('/subscriptions/sub_123'))->last();
        $this->assertSame('false', $resumed->data()['cancel_at_period_end']);
        $this->assertSame(Subscription::STATUS_ACTIVE, $tenant->subscription->refresh()->status);
        $this->assertNotNull(SubscriptionEvent::where('type', 'reactivated')->first());
    }
}
