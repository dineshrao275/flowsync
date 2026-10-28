<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The provider-side lifecycle around a tenant's customer record, saved card, trial subscription and
 * portal: everything PaymentService used to do for tenants that are not paying yet. Depends only on
 * the gateway resolver; PaymentService keeps delegating methods so callers are unchanged.
 */
class ProviderTrials
{
    public function __construct(private readonly PaymentResolver $resolver) {}

    /** Whether the gateway that would bill this tenant can save a card for a trial. */
    public function canCaptureCard(Tenant $tenant): bool
    {
        return $this->resolver->resolveForTenant($tenant) instanceof CardCaptureGateway;
    }

    /** @return array{id: string, url: string} */
    public function createCardSession(Tenant $tenant, string $successUrl, string $cancelUrl): array
    {
        $gateway = $this->resolver->resolveForTenant($tenant);
        if (! $gateway instanceof CardCaptureGateway) {
            throw new RuntimeException('Card capture is not available for this payment provider.');
        }

        return $gateway->createCardSession($tenant, $successUrl, $cancelUrl);
    }

    /** @return array{complete: bool, reference: string|null, payment_method: string|null} */
    public function retrieveCardSession(Tenant $tenant, string $sessionId): array
    {
        $gateway = $this->resolver->resolveForTenant($tenant);

        return $gateway instanceof CardCaptureGateway
            ? $gateway->retrieveCardSession($sessionId)
            : ['complete' => false, 'reference' => null, 'payment_method' => null];
    }

    /**
     * Create the tenant's customer record at the provider (idempotent: a tenant has one). Never throws — a provider
     * hiccup must not undo a provisioned tenant — but a failure is remembered on the tenant so `billing:reconcile` retries it.
     */
    public function ensureCustomer(Tenant $tenant): ?string
    {
        $gateway = $this->resolver->resolveForTenant($tenant);
        if (! $gateway instanceof CardCaptureGateway) {
            return null;
        }

        try {
            $id = $gateway->ensureCustomer($tenant);
            $this->clearSyncError($tenant, 'customer');

            return $id;
        } catch (\Throwable $e) {
            $this->recordSyncError($tenant, 'customer', $e);

            return null;
        }
    }

    /**
     * After sign-up / tenant creation: have the provider bill the plan when the trial ends. `$paymentMethod` may be null (a
     * tenant created by an administrator) — the provider subscription then cancels itself at trial end unless a card arrives.
     *
     * Provider first, local second: if saving the reference fails after the provider created the subscription, the provider
     * subscription is cancelled again (compensation) so no billable subscription exists that the application does not know.
     */
    public function startProviderTrial(Tenant $tenant, ?string $paymentMethod, int $trialDays, ?string $product = null): void
    {
        $subscription = $product ? $tenant->subscriptionFor($product) : $tenant->subscription;
        $gateway = $this->resolver->resolveForTenant($tenant);
        if (! $subscription?->plan || $subscription->plan->price_cents <= 0 || ! $gateway instanceof CardCaptureGateway
            || $subscription->provider_subscription_id) {
            return;
        }

        $result = $gateway->startTrialSubscription($tenant, $subscription->plan, $subscription->plan->currency ?? 'usd', $paymentMethod, max(1, $trialDays));

        try {
            $subscription->update([
                'billing_provider' => $gateway->name(),
                'provider_subscription_id' => $result['subscription_id'],
            ]);
        } catch (\Throwable $e) {
            if ($gateway instanceof RecurringGateway) {
                try {
                    $gateway->cancelSubscription($result['subscription_id']);
                } catch (\Throwable $undo) {
                    Log::critical('Provider subscription exists that the database does not know — cancel it by hand.', ['tenant_id' => $tenant->id, 'provider_subscription_id' => $result['subscription_id'], 'error' => $undo->getMessage()]);
                }
            }

            throw $e;
        }
    }

    /**
     * Give every trialing subscription of the tenant a provider subscription (and attach the card): the repair for a
     * provisioning-time provider failure and the step that follows a card being added to a card-less trial.
     */
    public function syncProviderTrials(Tenant $tenant, ?string $paymentMethod = null): int
    {
        $started = 0;

        foreach ($tenant->subscriptions()->where('status', Subscription::STATUS_TRIALING)->whereNull('provider_subscription_id')->with('plan')->get() as $subscription) {
            $days = $subscription->trial_ends_at ? (int) ceil(now()->diffInHours($subscription->trial_ends_at, false) / 24) : 0;
            if ($days < 1 || ($subscription->plan?->price_cents ?? 0) <= 0) {
                continue;
            }

            try {
                $this->startProviderTrial($tenant, $paymentMethod, $days, $subscription->product);
                $started++;
                $this->clearSyncError($tenant, 'trial');
            } catch (\Throwable $e) {
                $this->recordSyncError($tenant, 'trial', $e);
            }
        }

        return $started;
    }

    public function recordSyncError(Tenant $tenant, string $step, \Throwable $e): void
    {
        Log::warning("Billing provider sync failed at [{$step}].", ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);

        $settings = (array) $tenant->settings;
        $settings['billing']['sync_errors'][$step] = ['message' => Str::limit($e->getMessage(), 200), 'at' => now()->toIso8601String()];
        $tenant->forceFill(['settings' => $settings])->saveQuietly();
    }

    public function clearSyncError(Tenant $tenant, string $step): void
    {
        $settings = (array) $tenant->settings;
        if (isset($settings['billing']['sync_errors'][$step])) {
            unset($settings['billing']['sync_errors'][$step]);
            $tenant->forceFill(['settings' => $settings])->saveQuietly();
        }
    }

    /** Stop (or resume) renewal at the provider; a no-op for subscriptions it does not bill. */
    public function setProviderCancelAtPeriodEnd(Tenant $tenant, bool $cancel, ?string $product = null): void
    {
        $subscription = $product ? $tenant->subscriptionFor($product) : $tenant->subscription;
        $gateway = $subscription?->billing_provider ? $this->resolver->resolve($subscription->billing_provider) : null;

        if ($gateway instanceof RecurringGateway && $subscription->provider_subscription_id) {
            $gateway->setCancelAtPeriodEnd($subscription->provider_subscription_id, $cancel);
        }
    }

    /** Hosted billing portal (update card, see invoices) for a tenant billed through a recurring gateway. */
    public function portalUrl(Tenant $tenant, string $returnUrl): string
    {
        $gateway = $this->resolver->resolveForTenant($tenant);
        if (! $gateway instanceof RecurringGateway) {
            throw new RuntimeException('The billing portal is not available for this payment provider.');
        }

        return $gateway->portalUrl($tenant, $returnUrl);
    }
}
