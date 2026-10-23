<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Billing\Stripe\BillingProviderException;
use App\Billing\Stripe\CatalogSync;
use App\Models\SubscriptionPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes one plan to Stripe (product + price). Retries with backoff while Stripe is unreachable; a permanent refusal is
 * recorded on the plan (`stripe_sync_error`, shown to the super admin) instead of retrying forever. Unique per plan so a
 * burst of edits collapses into one run that reads the latest row.
 */
class SyncPlanToStripe implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 60;

    public function __construct(public SubscriptionPlan $plan) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function uniqueId(): string
    {
        return 'plan-'.$this->plan->id;
    }

    public function handle(CatalogSync $catalog): void
    {
        try {
            $catalog->syncPlan($this->plan->fresh() ?? $this->plan);
        } catch (BillingProviderException $e) {
            if (! $e->retryable) {
                $this->fail($e); // recorded on the plan by CatalogSync; retrying a rejected request cannot help

                return;
            }

            throw $e;
        }
    }
}
