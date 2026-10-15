<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payment record for a tenant (system DB).
 *
 * Tracks hosted checkouts, charges, webhooks, and refunds across Stripe, Razorpay, or Mock gateways.
 * All state mutations are conducted in DB transactions and logged in payment_events.
 */
class Payment extends Model
{
    use CentralConnection;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public const PROVIDER_STRIPE = 'stripe';

    public const PROVIDER_RAZORPAY = 'razorpay';

    public const PROVIDER_MOCK = 'mock';

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'user_id',
        'provider',
        'provider_payment_id',
        'provider_order_id',
        'amount_cents',
        'currency',
        'status',
        'idempotency_key',
        'failure_reason',
        'fee_cents',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'fee_cents' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isRefunded(): bool
    {
        return in_array($this->status, [self::STATUS_REFUNDED, self::STATUS_PARTIALLY_REFUNDED], true);
    }

    public function formattedAmount(): string
    {
        $amount = number_format($this->amount_cents / 100, 2);

        return strtoupper($this->currency).' '.$amount;
    }
}
