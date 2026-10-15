<?php

namespace App\Billing\Invoices;

use App\Models\Invoice;
use App\Support\Pdf\SimplePdf;

/** Renders one invoice to a PDF document (bytes). */
class InvoicePdf
{
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing('lines');
        $right = SimplePdf::WIDTH - SimplePdf::MARGIN;
        $money = fn (int $cents): string => $this->money($cents, $invoice->currency);

        $pdf = (new SimplePdf)
            ->line((string) config('billing.invoice.issuer'), 18, true)
            ->gap(4)
            ->row([['Invoice '.$invoice->number, SimplePdf::MARGIN, false], [strtoupper($invoice->status), $right, true]], 12, true)
            ->gap(4)
            ->line('Issued: '.($invoice->issued_at?->toFormattedDateString() ?? '-')
                .($invoice->paid_at ? '   Paid: '.$invoice->paid_at->toFormattedDateString() : ''))
            ->line('Billing period: '.($invoice->period_start?->toFormattedDateString() ?? '-').' to '.($invoice->period_end?->toFormattedDateString() ?? '-'))
            ->gap(10)
            ->line('Bill to', 9, true)
            ->line((string) ($invoice->billing_name ?? ''))
            ->line((string) ($invoice->billing_email ?? ''))
            ->gap(14)
            ->row([['Description', SimplePdf::MARGIN], ['Qty', 360.0, true], ['Unit', 440.0, true], ['Amount', $right, true]], 10, true)
            ->rule();

        foreach ($invoice->lines as $line) {
            $pdf->row([
                [mb_strimwidth($line->description, 0, 62, '...'), SimplePdf::MARGIN],
                [(string) $line->quantity, 360.0, true],
                [$money($line->unit_amount_cents), 440.0, true],
                [$money($line->amount_cents), $right, true],
            ]);
        }

        $pdf->rule()
            ->row([['Subtotal', 440.0, true], [$money($invoice->subtotal_cents), $right, true]])
            ->row([['Tax', 440.0, true], [$money($invoice->tax_cents), $right, true]])
            ->row([['Total', 440.0, true], [$money($invoice->total_cents), $right, true]], 11, true)
            ->row([['Amount paid', 440.0, true], [$money($invoice->amount_paid_cents), $right, true]])
            ->gap(20)
            ->line((string) config('billing.invoice.footer'), 9);

        return $pdf->output();
    }

    public function filename(Invoice $invoice): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9_-]/', '', $invoice->number ?? 'invoice-'.$invoice->id)).'.pdf';
    }

    private function money(int $cents, string $currency): string
    {
        return strtoupper($currency).' '.number_format($cents / 100, 2);
    }
}
