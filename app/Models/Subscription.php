<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant's subscription (system DB).
 *
 * One row per tenant — plan changes re-stamp this row and write a
 * SubscriptionEvent (see SubscriptionService).
 */
class Subscription extends Model
{
    use CentralConnection;

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_ENDED = 'ended';

    // subscription_events.type
    public const EVENT_SUBSCRIBED = 'subscribed';

    public const EVENT_PLAN_CHANGED = 'plan_changed';

    public const EVENT_RENEWED = 'renewed';

    public const EVENT_TRIAL_STARTED = 'trial_started';

    public const EVENT_TRIAL_EXPIRED = 'trial_expired';

    public const EVENT_CANCELED = 'canceled';

    public const EVENT_REACTIVATED = 'reactivated';

    public const EVENT_PAUSED = 'paused';

    public const EVENT_PAYMENT_FAILED = 'payment_failed';

    public const EVENT_SEATS_CHANGED = 'seats_changed';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'canceled_at',
        'auto_renew',
        'seats',
        'billing_provider',
        'billing_reference',
    ];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'auto_renew' => 'boolean',
            'seats' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class);
    }

    /**
     * Subscription currently usable by the tenant (trial or active).
     */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_TRIALING, self::STATUS_ACTIVE], true);
    }

    public function onTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING;
    }

    public function isCanceled(): bool
    {
        return $this->status === self::STATUS_CANCELED;
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAST_DUE;
    }
}
