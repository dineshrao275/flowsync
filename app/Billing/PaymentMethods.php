<?php

namespace App\Billing;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * Saved cards of a tenant: list, add (hosted setup page — card numbers never touch our servers), make default, remove.
 * The provider is the source of truth; a one-card summary of the DEFAULT card is cached on the tenant
 * (`settings.billing.card`) so expiry warnings and the subscription page need no provider call.
 */
class PaymentMethods
{
    public function __construct(private readonly PaymentResolver $resolver) {}

    /** @return array{methods: list<array<string, mixed>>, default_id: string|null, supported: bool} */
    public function list(Tenant $tenant): array
    {
        $gateway = $this->gateway($tenant);
        if (! $gateway) {
            return ['methods' => [], 'default_id' => null, 'supported' => false];
        }

        $methods = $gateway->paymentMethods($tenant);
        $default = collect($methods)->firstWhere('is_default', true);
        $this->remember($tenant, $default);

        return ['methods' => $methods, 'default_id' => $default['id'] ?? null, 'supported' => true];
    }

    /** Hosted page for adding a card; returns its URL. */
    public function startSetup(Tenant $tenant, string $successUrl, string $cancelUrl): string
    {
        $gateway = $this->gateway($tenant);
        if (! $gateway instanceof CardCaptureGateway) {
            throw ValidationException::withMessages(['form' => __('Saving a card is not available for this billing provider.')]);
        }

        return $gateway->createCardSession($tenant, $successUrl, $cancelUrl)['url'];
    }

    /** After the hosted page: verify the session is this tenant's and make the new card the default. */
    public function completeSetup(Tenant $tenant, string $sessionId): void
    {
        $gateway = $this->gateway($tenant);
        if (! $gateway instanceof CardCaptureGateway) {
            throw ValidationException::withMessages(['form' => __('Saving a card is not available for this billing provider.')]);
        }

        $session = $gateway->retrieveCardSession($sessionId);
        if (! $session['complete'] || (string) $session['reference'] !== (string) $tenant->id || ! $session['payment_method']) {
            throw ValidationException::withMessages(['session_id' => __('That card setup was not completed for this organisation.')]);
        }

        $this->makeDefault($tenant, $session['payment_method']);
    }

    public function makeDefault(Tenant $tenant, string $paymentMethodId): void
    {
        $gateway = $this->gateway($tenant);
        $this->assertOwned($gateway, $tenant, $paymentMethodId);

        $gateway->setDefaultPaymentMethod($tenant, $paymentMethodId, $this->providerSubscriptionIds($tenant));
        $this->list($tenant); // refresh the cached default

        // A card-less trial is complete now: the product opens, and any trial the provider does not know yet starts with this card.
        $settings = (array) $tenant->settings;
        if (! empty($settings['billing']['card_required'])) {
            unset($settings['billing']['card_required']);
            $tenant->forceFill(['settings' => $settings])->saveQuietly();
        }
        app(PaymentService::class)->syncProviderTrials($tenant->refresh(), $paymentMethodId);
    }

    public function remove(Tenant $tenant, string $paymentMethodId): void
    {
        $gateway = $this->gateway($tenant);
        $methods = $this->assertOwned($gateway, $tenant, $paymentMethodId);
        $target = collect($methods)->firstWhere('id', $paymentMethodId);

        // The default card pays the next invoice: it cannot go while one is coming and no other card could take over.
        if (($target['is_default'] ?? false) && $this->providerSubscriptionIds($tenant) !== []) {
            throw ValidationException::withMessages(['form' => __('Make another card the default before removing this one — your subscription renews with it.')]);
        }

        $gateway->detachPaymentMethod($paymentMethodId);
        $this->list($tenant);
    }

    /** @param  array{brand?: string, last4?: string, exp_month?: int, exp_year?: int, id?: string}|null  $card */
    public function remember(Tenant $tenant, ?array $card): void
    {
        $settings = (array) $tenant->settings;
        $current = $settings['billing']['card'] ?? null;
        $next = $card ? array_intersect_key($card, array_flip(['id', 'brand', 'last4', 'exp_month', 'exp_year'])) : null;

        if ($current === $next) {
            return;
        }

        if ($next === null) {
            unset($settings['billing']['card']);
        } else {
            $settings['billing']['card'] = $next;
        }
        $tenant->forceFill(['settings' => $settings])->saveQuietly();
    }

    /** @return list<string> provider subscription ids that bill the tenant (they charge the default card) */
    private function providerSubscriptionIds(Tenant $tenant): array
    {
        return $tenant->subscriptions()->whereNotNull('provider_subscription_id')
            ->whereNotIn('status', [Subscription::STATUS_ENDED, Subscription::STATUS_EXPIRED, Subscription::STATUS_CANCELED])
            ->pluck('provider_subscription_id')->all();
    }

    private function gateway(Tenant $tenant): ?RecurringGateway
    {
        $gateway = $this->resolver->resolveForTenant($tenant, config('payments.default_currency', 'usd'));

        return $gateway instanceof RecurringGateway ? $gateway : null;
    }

    /** @return list<array<string, mixed>> the tenant's cards; 422 unless `$paymentMethodId` is one of them */
    private function assertOwned(?RecurringGateway $gateway, Tenant $tenant, string $paymentMethodId): array
    {
        if (! $gateway) {
            throw ValidationException::withMessages(['form' => __('Managing cards is not available for this billing provider.')]);
        }

        $methods = $gateway->paymentMethods($tenant);
        if (! collect($methods)->contains('id', $paymentMethodId)) {
            throw ValidationException::withMessages(['payment_method' => __('That card does not belong to this organisation.')]);
        }

        return $methods;
    }
}
