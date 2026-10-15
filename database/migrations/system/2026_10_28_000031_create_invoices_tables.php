<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6.1 — the invoice entity (system DB). One invoice per settled charge: a
 * checkout payment, a provider renewal (Stripe invoice.paid) or a proration.
 * Repair-safe: every create is guarded so a re-run after a partial failure
 * finishes the job instead of throwing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
                $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->nullOnDelete();
                // FS-000042: derived from the row id inside the creating transaction.
                $table->string('number', 32)->nullable()->unique();
                $table->string('status', 24)->default('open')->index(); // open | paid | void
                $table->string('currency', 3)->default('usd');
                $table->unsignedBigInteger('subtotal_cents')->default(0);
                $table->unsignedBigInteger('tax_cents')->default(0);
                $table->unsignedBigInteger('total_cents')->default(0);
                $table->unsignedBigInteger('amount_paid_cents')->default(0);
                $table->string('provider', 32)->nullable();
                // The provider's own invoice id; replayed webhooks resolve to the same row.
                $table->string('provider_invoice_id', 255)->nullable();
                $table->string('billing_name', 255)->nullable();
                $table->string('billing_email', 255)->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('period_start')->nullable();
                $table->timestamp('period_end')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'provider_invoice_id']);
                $table->index(['tenant_id', 'issued_at']);
            });
        }

        if (! Schema::hasTable('invoice_lines')) {
            Schema::create('invoice_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->string('kind', 24)->default('subscription'); // subscription | proration | credit | other
                $table->string('description', 500);
                $table->unsignedInteger('quantity')->default(1);
                // Signed: a proration credit is a negative line.
                $table->bigInteger('unit_amount_cents')->default(0);
                $table->bigInteger('amount_cents')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
