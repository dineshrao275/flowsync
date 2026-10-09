<?php

namespace App\Billing\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * The one writer of invoices (system DB). An invoice is the billing document
 * for a charge that already happened (or, for a proration, is now owed), so
 * every entry point is idempotent: a replayed webhook or a re-run verify
 * resolves to the invoice it already created instead of billing twice.
 */
class InvoiceService
{
    /**
     * Invoice a completed payment. Lines default to one subscription line for
     * the payment's plan; a provider that itemises (Stripe) passes its own.
     *
     * @param  array<int, array{description: string, amount_cents: int, kind?: string, quantity?: int}>|null  $lines
     */
    public function issueForPayment(
        Payment $payment,
        ?array $lines = null,
        ?string $providerInvoiceId = null,
        ?Carbon $periodStart = null,
        ?Carbon $periodEnd = null,
        int $taxCents = 0,
    ): Invoice {
        $existing = Invoice::where('payment_id', $payment->id)->first();
        if ($existing) {
            return $existing;
        }

        $tenant = Tenant::findOrFail($payment->tenant_id);
        $subscription = $payment->subscription_id ? Subscription::find($payment->subscription_id) : null;
        $lines ??= [[
            'description' => $this->describe($payment, $subscription),
            'amount_cents' => $payment->amount_cents,
        ]];

        return $this->create($tenant, $subscription, $lines, [
            'payment_id' => $payment->id,
            'status' => Invoice::STATUS_PAID,
            'currency' => strtolower($payment->currency),
            'provider' => $payment->provider,
            'provider_invoice_id' => $providerInvoiceId,
            'issued_at' => now(),
            'paid_at' => now(),
            'period_start' => $periodStart ?? $subscription?->current_period_start,
            'period_end' => $periodEnd ?? $subscription?->current_period_end,
            'tax_cents' => $taxCents,
            'amount_paid_cents' => $payment->amount_cents,
        ]);
    }

    /**
     * An open invoice for charges owed but not yet collected (a mid-period
     * proration on a plan the provider does not bill itself).
     *
     * @param  array<int, array{description: string, amount_cents: int, kind?: string, quantity?: int}>  $lines
     */
    public function issueOpen(Tenant $tenant, ?Subscription $subscription, array $lines, array $metadata = []): Invoice
    {
        return $this->create($tenant, $subscription, $lines, [
            'status' => Invoice::STATUS_OPEN,
            'currency' => strtolower($subscription?->plan?->currency ?? config('payments.default_currency', 'usd')),
            'issued_at' => now(),
            'period_start' => $subscription?->current_period_start,
            'period_end' => $subscription?->current_period_end,
            'metadata' => $metadata ?: null,
        ]);
    }

    /** Settle an open invoice against the payment that collected it. */
    public function markPaid(Invoice $invoice, Payment $payment): Invoice
    {
        if ($invoice->status === Invoice::STATUS_OPEN) {
            $invoice->update([
                'status' => Invoice::STATUS_PAID,
                'payment_id' => $payment->id,
                'amount_paid_cents' => $invoice->total_cents,
                'paid_at' => now(),
            ]);
        }

        return $invoice;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function create(Tenant $tenant, ?Subscription $subscription, array $lines, array $attributes): Invoice
    {
        $invoice = new Invoice;

        return $invoice->getConnection()->transaction(function () use ($invoice, $tenant, $subscription, $lines, $attributes): Invoice {
            $subtotal = (int) collect($lines)->sum('amount_cents');

            $invoice->fill($attributes + [
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription?->id,
                'subtotal_cents' => $subtotal,
                'total_cents' => $subtotal + (int) ($attributes['tax_cents'] ?? 0),
                'billing_name' => $tenant->name,
                'billing_email' => $tenant->billing_email ?? $tenant->contact_email,
            ] + ['tax_cents' => 0])->save();

            $invoice->update(['number' => sprintf('%s-%06d', config('billing.invoice.prefix', 'FS'), $invoice->id)]);

            foreach ($lines as $line) {
                $quantity = max(1, (int) ($line['quantity'] ?? 1));
                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'kind' => $line['kind'] ?? InvoiceLine::KIND_SUBSCRIPTION,
                    'description' => mb_substr((string) $line['description'], 0, 500),
                    'quantity' => $quantity,
                    'unit_amount_cents' => intdiv((int) $line['amount_cents'], $quantity),
                    'amount_cents' => (int) $line['amount_cents'],
                ]);
            }

            return $invoice;
        });
    }

    private function describe(Payment $payment, ?Subscription $subscription): string
    {
        $plan = $payment->metadata['plan_name'] ?? $subscription?->plan?->name ?? 'Subscription';
        $cycle = $subscription?->plan?->billing_cycle;

        return trim($plan.' plan'.($cycle ? " ({$cycle})" : ''));
    }
}
