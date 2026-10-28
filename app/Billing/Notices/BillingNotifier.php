<?php

declare(strict_types=1);

namespace App\Billing\Notices;

use App\Mail\BillingNoticeMail;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceNotice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Every billing email goes through here: it works out who should hear (the billing and contact addresses plus the
 * tenant's admins), composes the copy in the tenant's language, and queues one self-contained mailable per person.
 * A mail failure is logged and never undoes the billing change that triggered it.
 */
class BillingNotifier
{
    public function __construct(private readonly TenantDatabaseManager $tenants) {}

    public function priceChange(SubscriptionPriceNotice $notice): bool
    {
        $notice->loadMissing(['change.plan', 'tenant']);
        $change = $notice->change;
        $tenant = $notice->tenant;

        $sent = $this->send($tenant, function () use ($notice, $change, $tenant): array {
            $plan = $change->plan->name;
            $params = [
                'app' => config('app.name'),
                'organisation' => $tenant->name,
                'plan' => $plan,
                'old' => $this->money($notice->old_amount_cents, $notice->currency),
                'new' => $this->money($notice->new_amount_cents, $notice->currency),
                'date' => $this->date($notice->effective_at),
                'cycle' => __('billing.price_change.cycle_'.($change->billing_cycle === 'annual' ? 'annual' : 'monthly')),
            ];

            return [
                __($change->isIncrease() ? 'billing.price_change.subject_up' : 'billing.price_change.subject_down', $params),
                __('billing.price_change.greeting', $params),
                [__($change->isIncrease() ? 'billing.price_change.increase' : 'billing.price_change.decrease', $params), __('billing.price_change.nothing_now'), __('billing.price_change.options')],
            ];
        });

        if ($sent) {
            $notice->forceFill(['notified_at' => now()])->save();
        }

        return $sent;
    }

    public function trialEnding(Tenant $tenant, Carbon $endsAt): bool
    {
        return $this->send($tenant, fn () => [
            __('billing.trial_ending.subject', ['app' => config('app.name'), 'date' => $this->date($endsAt)]),
            __('billing.price_change.greeting', ['organisation' => $tenant->name]),
            [__('billing.trial_ending.body', ['app' => config('app.name'), 'organisation' => $tenant->name, 'date' => $this->date($endsAt)])],
        ]);
    }

    /** @param  array{brand?: string, last4?: string, exp_month?: int, exp_year?: int}  $card */
    public function cardExpiring(Tenant $tenant, array $card): bool
    {
        return $this->send($tenant, fn () => [
            __('billing.card_expiring.subject', ['app' => config('app.name')]),
            __('billing.price_change.greeting', ['organisation' => $tenant->name]),
            [__('billing.card_expiring.body', ['brand' => ucfirst((string) ($card['brand'] ?? 'card')), 'last4' => (string) ($card['last4'] ?? '••••'), 'expires' => sprintf('%02d/%d', $card['exp_month'] ?? 0, $card['exp_year'] ?? 0)])],
        ]);
    }

    public function paymentActionRequired(Tenant $tenant): bool
    {
        return $this->send($tenant, fn () => [
            __('billing.payment_action_required.subject', ['app' => config('app.name')]),
            __('billing.price_change.greeting', ['organisation' => $tenant->name]),
            [__('billing.payment_action_required.body', ['app' => config('app.name')])],
        ]);
    }

    public function planChangeScheduled(Tenant $tenant, SubscriptionPlan $from, SubscriptionPlan $to, Carbon $at): bool
    {
        return $this->send($tenant, fn () => [
            __('billing.plan_change_scheduled.subject'),
            __('billing.price_change.greeting', ['organisation' => $tenant->name]),
            [__('billing.plan_change_scheduled.body', ['from' => $from->name, 'to' => $to->name, 'date' => $this->date($at)])],
        ]);
    }

    /** @return list<string> lower-cased, de-duplicated addresses */
    public function recipients(Tenant $tenant): array
    {
        $emails = [$tenant->billing_email, $tenant->contact_email];

        try {
            if ($tenant->isProvisioned()) {
                $emails = [...$emails, ...$this->tenants->using($tenant, fn () => User::whereHas('roles', fn ($q) => $q->where('slug', 'admin'))->pluck('email')->all())];
            }
        } catch (Throwable $e) {
            Log::warning('Could not read tenant admins for a billing notice.', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
        }

        return array_values(array_unique(array_filter(array_map(fn ($e) => $e ? mb_strtolower(trim($e)) : null, $emails))));
    }

    /** @param  callable(): array{0: string, 1: string, 2: list<string>}  $compose */
    private function send(Tenant $tenant, callable $compose): bool
    {
        $to = $this->recipients($tenant);
        if ($to === []) {
            return false;
        }

        $previous = App::getLocale();
        try {
            App::setLocale($tenant->locale ?: config('app.locale'));
            [$subject, $greeting, $lines] = $compose();
            $url = url('/app/subscription');
            $lines[] = __('billing.price_change.link', ['url' => $url]);
            foreach ($to as $address) {
                Mail::to($address)->queue(new BillingNoticeMail($subject, $greeting, $lines));
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('Billing notice failed.', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);

            return false;
        } finally {
            App::setLocale($previous);
        }
    }

    private function money(int $cents, string $currency): string
    {
        return strtoupper($currency).' '.number_format($cents / 100, 2);
    }

    private function date(Carbon $at): string
    {
        return $at->translatedFormat('j F Y');
    }
}
