<?php

declare(strict_types=1);

namespace App\Billing\Invoices;

use App\Models\Invoice;

class InvoicePresenter
{
    /** @return array<string, mixed> */
    public static function present(Invoice $invoice, bool $withLines = false): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'currency' => strtoupper($invoice->currency),
            'subtotal_cents' => $invoice->subtotal_cents,
            'tax_cents' => $invoice->tax_cents,
            'total_cents' => $invoice->total_cents,
            'amount_paid_cents' => $invoice->amount_paid_cents,
            'formatted_total' => strtoupper($invoice->currency).' '.number_format($invoice->total_cents / 100, 2),
            'provider' => $invoice->provider,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'period_start' => $invoice->period_start?->toIso8601String(),
            'period_end' => $invoice->period_end?->toIso8601String(),
            // The provider's own hosted invoice page / PDF (receipt), when it issued one.
            'hosted_invoice_url' => $invoice->metadata['hosted_invoice_url'] ?? null,
            'provider_pdf_url' => $invoice->metadata['invoice_pdf'] ?? null,
            'receipt_number' => $invoice->metadata['receipt_number'] ?? null,
            'tenant' => $invoice->relationLoaded('tenant') && $invoice->tenant
                ? ['id' => $invoice->tenant->id, 'name' => $invoice->tenant->name, 'slug' => $invoice->tenant->slug]
                : null,
            'lines' => $withLines ? $invoice->lines->map(fn ($l) => [
                'kind' => $l->kind,
                'description' => $l->description,
                'quantity' => $l->quantity,
                'amount_cents' => $l->amount_cents,
            ])->values() : null,
        ];
    }
}
