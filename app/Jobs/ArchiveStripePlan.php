<?php

namespace App\Jobs;

use App\Billing\Stripe\CatalogSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Archives the Stripe product/price of a plan that was deleted (ids only: the row is gone). */
class ArchiveStripePlan implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public function __construct(public ?string $productId, public ?string $priceId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(CatalogSync $catalog): void
    {
        $catalog->archive($this->productId, $this->priceId);
    }
}
