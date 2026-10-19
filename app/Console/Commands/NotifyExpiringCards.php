<?php

namespace App\Console\Commands;

use App\Billing\Notices\BillingNotifier;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Warns the billing contacts before the saved card expires (config billing.card_expiry_warning_days, default 30 and 7
 * days), once per threshold per card, only for tenants that will actually be charged again.
 */
class NotifyExpiringCards extends Command
{
    protected $signature = 'billing:card-expiry';

    protected $description = 'Email tenants whose saved card is about to expire';

    public function handle(BillingNotifier $notifier): int
    {
        $thresholds = collect((array) config('billing.card_expiry_warning_days', [30, 7]))->sort()->values();
        $sent = 0;

        Tenant::whereNotNull('billing_customer_id')->get()->each(function (Tenant $tenant) use ($notifier, $thresholds, &$sent): void {
            $card = $tenant->settings['billing']['card'] ?? null;
            if (! $card || empty($card['exp_year']) || empty($card['exp_month'])) {
                return;
            }

            $billed = $tenant->subscriptions()->whereNotNull('provider_subscription_id')->where('auto_renew', true)
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING, Subscription::STATUS_PAST_DUE])->exists();
            if (! $billed) {
                return;
            }

            // A card is valid through the END of its expiry month.
            $expires = CarbonImmutable::create((int) $card['exp_year'], (int) $card['exp_month'], 1)->endOfMonth();
            $days = (int) now()->startOfDay()->diffInDays($expires, false);

            // The tightest threshold we are inside of; Cache::add makes it once per card per threshold.
            $threshold = $thresholds->first(fn (int $t) => $days >= 0 && $days <= $t);
            if ($threshold === null || ! Cache::add("billing:card-expiry:{$tenant->id}:{$card['id']}:{$threshold}", 1, now()->addDays(60))) {
                return;
            }

            $notifier->cardExpiring($tenant, $card) && $sent++;
        });

        $this->info("notified {$sent}");

        return self::SUCCESS;
    }
}
