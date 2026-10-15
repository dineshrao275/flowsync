<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an invoice (system DB). Amounts are signed: a proration credit is negative. */
class InvoiceLine extends Model
{
    use CentralConnection;

    public const KIND_SUBSCRIPTION = 'subscription';

    public const KIND_PRORATION = 'proration';

    public const KIND_CREDIT = 'credit';

    public const KIND_OTHER = 'other';

    protected $fillable = ['invoice_id', 'kind', 'description', 'quantity', 'unit_amount_cents', 'amount_cents', 'metadata'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount_cents' => 'integer',
            'amount_cents' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
