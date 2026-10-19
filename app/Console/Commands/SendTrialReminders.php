<?php

namespace App\Console\Commands;

use App\Billing\Notices\BillingNotifier;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Tells tenants on a trial WITHOUT a card that it ends soon (Stripe's own `trial_will_end` covers trials with a card).
 * Once per tenant per trial end date. When the trial runs out, `tenants:expire-trials` confines the tenant to billing —
 * data intact — so this is the heads-up, not the cut-off.
 */
class SendTrialReminders extends Command
{
    protected $signature = 'billing:trial-reminders {--days=3 : warn when the trial ends within this many days}';

    protected $description = 'Email tenants whose card-less trial is about to end';

    public function handle(BillingNotifier $notifier): int
    {
        $days = max(1, (int) $this->option('days'));
        $sent = 0;

        Tenant::where('status', Tenant::STATUS_TRIAL)->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [now(), now()->addDays($days)])->get()
            ->each(function (Tenant $tenant) use ($notifier, &$sent): void {
                $cardOnFile = $tenant->subscriptions()->whereNotNull('provider_subscription_id')->where('status', Subscription::STATUS_TRIALING)->exists();
                if ($cardOnFile || ! Cache::add("billing:trial-reminder:{$tenant->id}:{$tenant->trial_ends_at->toDateString()}", 1, now()->addDays(10))) {
                    return;
                }

                $notifier->trialEnding($tenant, $tenant->trial_ends_at) && $sent++;
            });

        $this->info("reminded {$sent}");

        return self::SUCCESS;
    }
}
