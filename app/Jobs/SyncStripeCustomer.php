<?php

namespace App\Jobs;

use App\Billing\Stripe\CatalogSync;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Keeps the tenant's Stripe Customer (name, billing email) equal to the organisation. */
class SyncStripeCustomer implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(public Tenant $tenant) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(CatalogSync $catalog): void
    {
        $catalog->syncCustomer($this->tenant->fresh() ?? $this->tenant);
    }
}
