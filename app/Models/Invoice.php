<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant invoice (system DB): the billing document for one settled charge.
 * Created by App\Billing\Invoices\InvoiceService, never by hand.
 */
class Invoice extends Model
{
    use CentralConnection;

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'tenant_id', 'subscription_id', 'payment_id', 'number', 'status', 'currency',
        'subtotal_cents', 'tax_cents', 'total_cents', 'amount_paid_cents',
        'provider', 'provider_invoice_id', 'billing_name', 'billing_email',
        'issued_at', 'paid_at', 'period_start', 'period_end', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('id');
    }
}
