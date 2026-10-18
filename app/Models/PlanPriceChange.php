<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One edit of a plan's price (system DB): what it was, what it became, and the notice period promised. */
class PlanPriceChange extends Model
{
    use CentralConnection;

    protected $fillable = ['plan_id', 'old_price_cents', 'new_price_cents', 'currency', 'billing_cycle', 'old_stripe_price_id', 'new_stripe_price_id', 'notice_days', 'announced_at'];

    protected function casts(): array
    {
        return ['old_price_cents' => 'integer', 'new_price_cents' => 'integer', 'notice_days' => 'integer', 'announced_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function notices(): HasMany
    {
        return $this->hasMany(SubscriptionPriceNotice::class);
    }

    public function isIncrease(): bool
    {
        return $this->new_price_cents > $this->old_price_cents;
    }
}
