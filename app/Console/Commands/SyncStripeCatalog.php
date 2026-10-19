<?php

namespace App\Console\Commands;

use App\Billing\Stripe\BillingProviderException;
use App\Billing\Stripe\CatalogSync;
use App\Models\SubscriptionPlan;
use Illuminate\Console\Command;

/** Reconciles every plan with Stripe (products, prices, archived plans) — run nightly and after configuring keys. */
class SyncStripeCatalog extends Command
{
    protected $signature = 'billing:sync-plans {--plan= : plan id or slug} {--dry-run}';

    protected $description = 'Synchronise subscription plans with Stripe products and prices';

    public function handle(CatalogSync $catalog): int
    {
        if (! $catalog->enabled()) {
            $this->warn('Stripe is not configured (STRIPE_SECRET); nothing to sync.');

            return self::SUCCESS;
        }

        $plans = SubscriptionPlan::query()
            ->when($this->option('plan'), fn ($q, $ref) => $q->where('slug', $ref)->orWhere('id', ctype_digit((string) $ref) ? (int) $ref : 0))
            ->orderBy('id')->get();

        $failed = 0;
        foreach ($plans as $plan) {
            if ($this->option('dry-run')) {
                $this->line(sprintf('%-22s %s', $plan->slug, $plan->stripe_synced_at ? 'synced '.$plan->stripe_synced_at->diffForHumans() : 'never synced'));

                continue;
            }
            try {
                $catalog->syncPlan($plan);
                $this->line(sprintf('%-22s ok', $plan->slug));
            } catch (BillingProviderException $e) {
                $failed++;
                $this->error(sprintf('%-22s %s', $plan->slug, $e->getMessage()));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
