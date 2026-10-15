<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log of payment events and incoming webhooks (system DB).
 *
 * provider_event_id is unique to prevent duplicate processing on webhook replays.
 */
class PaymentEvent extends Model
{
    use CentralConnection;

    public const TYPE_CHECKOUT_CREATED = 'checkout.created';

    public const TYPE_PAYMENT_SUCCEEDED = 'payment.succeeded';

    public const TYPE_PAYMENT_FAILED = 'payment.failed';

    public const TYPE_REFUND_CREATED = 'refund.created';

    public const TYPE_WEBHOOK_RECEIVED = 'webhook.received';

    protected $fillable = [
        'payment_id',
        'tenant_id',
        'type',
        'provider',
        'provider_event_id',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
