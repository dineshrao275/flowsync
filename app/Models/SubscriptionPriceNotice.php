<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What one subscriber was told about a price change, and when it starts to apply to them (system DB). */
class SubscriptionPriceNotice extends Model
{
    use CentralConnection;

    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['plan_price_change_id', 'subscription_id', 'tenant_id', 'old_amount_cents', 'new_amount_cents', 'currency', 'effective_at', 'status', 'notified_at', 'applied_at'];

    protected function casts(): array
    {
        return [
            'old_amount_cents' => 'integer', 'new_amount_cents' => 'integer',
            'effective_at' => 'datetime', 'notified_at' => 'datetime', 'applied_at' => 'datetime',
        ];
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(PlanPriceChange::class, 'plan_price_change_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
