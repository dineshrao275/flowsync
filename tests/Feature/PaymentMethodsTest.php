<?php

namespace Tests\Feature;

use App\Billing\PaymentMethods;
use App\Mail\BillingNoticeMail;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\StripeTestHelpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Saved cards: listed from Stripe, added through its hosted page, defaulted/removed with guards, expiry warnings. */
class PaymentMethodsTest extends TestCase
{
    use IsolatesDatabase;
    use StripeTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStripe();
        $this->stripeSubscriber('pro');
        Mail::fake();
        Http::fake([
            'api.stripe.com/v1/customers/cus_1' => Http::response(['id' => 'cus_1', 'invoice_settings' => ['default_payment_method' => 'pm_1']]),
            'api.stripe.com/v1/payment_methods/*/detach' => Http::response(['id' => 'pm_2']),
            'api.stripe.com/v1/payment_methods*' => Http::response(['data' => [
                ['id' => 'pm_1', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2031]],
                ['id' => 'pm_2', 'card' => ['brand' => 'mastercard', 'last4' => '4444', 'exp_month' => 1, 'exp_year' => 2030]],
            ]]),
            'api.stripe.com/v1/subscriptions/sub_123' => Http::response(['id' => 'sub_123']),
            'api.stripe.com/v1/checkout/sessions/cs_ok*' => Http::response([
                'id' => 'cs_ok', 'status' => 'complete', 'client_reference_id' => (string) $this->acme()->id,
                'setup_intent' => ['status' => 'succeeded', 'payment_method' => 'pm_2'],
            ]),
            'api.stripe.com/v1/checkout/sessions/cs_other*' => Http::response([
                'id' => 'cs_other', 'status' => 'complete', 'client_reference_id' => '99999',
                'setup_intent' => ['status' => 'succeeded', 'payment_method' => 'pm_2'],
            ]),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_new', 'url' => 'https://checkout.stripe.com/c/setup/cs_new']),
        ]);
        $this->loginAdmin();
    }

    public function test_cards_are_listed_with_the_default_marked_and_cached_for_expiry_warnings(): void
    {
        $r = $this->getJson('/api/billing/payment-methods')->assertOk();

        $r->assertJsonPath('default_id', 'pm_1')->assertJsonPath('methods.0.last4', '4242')->assertJsonPath('methods.0.is_default', true)->assertJsonPath('methods.1.is_default', false);
        $this->assertSame('visa', $this->acme()->settings['billing']['card']['brand']);
        $this->assertSame('4242', $this->acme()->settings['billing']['card']['last4']);
    }

    public function test_only_billing_admins_see_or_change_cards(): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'viewer@flowsync.test', 'password' => 'password'])->assertOk();

        $this->getJson('/api/billing/payment-methods')->assertForbidden();
        $this->postJson('/api/billing/payment-methods/setup')->assertForbidden();
        $this->postJson('/api/billing/payment-methods/pm_2/default')->assertForbidden();
        $this->deleteJson('/api/billing/payment-methods/pm_2')->assertForbidden();
    }

    public function test_a_card_is_added_through_the_hosted_page_and_becomes_the_default_for_future_invoices(): void
    {
        $this->postJson('/api/billing/payment-methods/setup')->assertOk()->assertJsonPath('url', 'https://checkout.stripe.com/c/setup/cs_new');
        $setup = collect($this->stripePosts('/checkout/sessions'))->first();
        $this->assertSame('setup', $setup->data()['mode']);
        $this->assertSame('cus_1', $setup->data()['customer']);

        $this->postJson('/api/billing/payment-methods/complete', ['session_id' => 'cs_ok'])->assertOk();

        // default on the customer AND on the subscription that was started with an explicit card
        $this->assertNotEmpty(collect($this->stripePosts('/customers/cus_1'))->filter(fn ($r) => ($r->data()['invoice_settings[default_payment_method]'] ?? null) === 'pm_2'));
        $this->assertNotEmpty(collect($this->stripePosts('/subscriptions/sub_123'))->filter(fn ($r) => ($r->data()['default_payment_method'] ?? null) === 'pm_2'));
    }

    public function test_a_setup_session_of_another_organisation_is_refused(): void
    {
        $this->postJson('/api/billing/payment-methods/complete', ['session_id' => 'cs_other'])->assertStatus(422);

        $this->assertCount(0, $this->stripePosts('/customers/cus_1'));
    }

    public function test_only_the_tenants_own_cards_can_be_defaulted_or_removed(): void
    {
        $this->postJson('/api/billing/payment-methods/pm_foreign/default')->assertStatus(422);
        $this->deleteJson('/api/billing/payment-methods/pm_foreign')->assertStatus(422);
    }

    public function test_the_default_card_cannot_be_removed_while_a_renewal_depends_on_it(): void
    {
        $this->deleteJson('/api/billing/payment-methods/pm_1')->assertStatus(422);
        $this->assertCount(0, $this->stripePosts('/payment_methods/pm_1/detach'));

        // a spare card can go
        $this->deleteJson('/api/billing/payment-methods/pm_2')->assertOk();
        $this->assertCount(1, $this->stripePosts('/payment_methods/pm_2/detach'));
    }

    public function test_card_webhooks_keep_the_cached_default_card_current(): void
    {
        $this->getJson('/api/billing/payment-methods')->assertOk(); // caches pm_1

        $this->stripeWebhook([
            'id' => 'evt_pm_up', 'type' => 'payment_method.automatically_updated',
            'data' => ['object' => ['id' => 'pm_1', 'customer' => 'cus_1', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 6, 'exp_year' => 2034]]],
        ])->assertOk()->assertJsonPath('status', 'processed');
        $this->assertSame(2034, $this->acme()->settings['billing']['card']['exp_year']);

        $this->stripeWebhook(['id' => 'evt_pm_det', 'type' => 'payment_method.detached', 'data' => ['object' => ['id' => 'pm_1', 'customer' => null]]])->assertOk();
        $this->assertArrayNotHasKey('card', $this->acme()->settings['billing'] ?? []);
    }

    public function test_an_expiring_card_is_warned_once_per_threshold_and_only_for_billed_tenants(): void
    {
        // valid through the END of its expiry month: this month is at most 30 days away, inside the first threshold
        app(PaymentMethods::class)->remember($this->acme(), ['id' => 'pm_1', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => now()->month, 'exp_year' => now()->year]);

        $this->artisan('billing:card-expiry')->assertSuccessful();
        Mail::assertQueued(BillingNoticeMail::class, fn (BillingNoticeMail $m) => $m->hasTo('billing@acme.test') && str_contains(implode(' ', $m->lines), '4242'));

        $this->artisan('billing:card-expiry')->assertSuccessful(); // same threshold: not again
        Mail::assertQueued(BillingNoticeMail::class, 3);

        // an unbilled tenant is never warned about a card nobody will charge
        Mail::fake();
        Cache::flush();
        $this->acme()->subscription->update(['status' => Subscription::STATUS_CANCELED, 'auto_renew' => false]);
        $this->artisan('billing:card-expiry')->assertSuccessful();
        Mail::assertNothingQueued();
    }
}
