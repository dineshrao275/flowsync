<?php

namespace Tests\Feature\Concerns;

use App\Billing\PaymentResolver;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/** Shared plumbing for the Stripe billing tests: configured keys, signed webhooks, a tenant that pays through Stripe. */
trait StripeTestHelpers
{
    protected const WEBHOOK_SECRET = 'whsec_unit_test';

    protected function configureStripe(): void
    {
        config([
            'payments.driver' => 'stripe',
            'payments.gateways.stripe.secret' => 'sk_test_unit',
            'payments.gateways.stripe.key' => 'pk_test_unit',
            'payments.gateways.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'billing.stripe.retry_sleep_ms' => 0,
        ]);
        app()->forgetInstance(PaymentResolver::class);
    }

    protected function plan(string $slug): SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', $slug)->firstOrFail();
    }

    protected function loginAdmin(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
    }

    protected function loginSuperAdmin(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'superadmin@flowsync.test', 'password' => 'password'])->assertOk();
    }

    protected function signedStripe(array $event): array
    {
        $body = json_encode($event);
        $t = time();

        return [$body, ['Stripe-Signature' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", self::WEBHOOK_SECRET), 'Content-Type' => 'application/json']];
    }

    protected function stripeWebhook(array $event)
    {
        [$body, $headers] = $this->signedStripe($event);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    /** acme paying `$planSlug` through Stripe subscription `$subId`, period ending in `$daysLeft` days. */
    protected function stripeSubscriber(string $planSlug = 'pro', string $subId = 'sub_123', int $daysLeft = 20): Tenant
    {
        $tenant = $this->acme();
        app(SubscriptionService::class)->assign($tenant, $this->plan($planSlug), ['billing_provider' => 'stripe']);
        $tenant->refresh()->subscription->update([
            'billing_provider' => 'stripe', 'provider_subscription_id' => $subId, 'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->subDays(30 - $daysLeft), 'current_period_end' => now()->addDays($daysLeft),
        ]);
        $tenant->update(['billing_customer_id' => 'cus_1', 'billing_email' => 'billing@acme.test']);

        return $tenant->refresh();
    }

    /** Posts sent to Stripe whose URL ends with `$suffix`. @return list<HttpRequest> */
    protected function stripePosts(string $suffix): array
    {
        $found = [];
        Http::assertSent(function (HttpRequest $r) use ($suffix, &$found) {
            if ($r->method() === 'POST' && str_ends_with($r->url(), $suffix)) {
                $found[] = $r;
            }

            return true;
        });

        return $found;
    }
}
