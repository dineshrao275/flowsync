<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6.2 — dunning. `subscriptions.past_due_at` anchors the day-count of a
 * failed-payment cycle; `dunning_attempts` is the ledger of what the sweep
 * did on which day, and its unique key is what makes a re-run (or a second
 * scheduler tick) do nothing. Repair-safe: guarded creates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subscriptions', 'past_due_at')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->timestamp('past_due_at')->nullable();
            });
        }

        if (! Schema::hasTable('dunning_attempts')) {
            Schema::create('dunning_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
                // The past_due_at of the cycle this belongs to (Ymd His): a later failure starts a fresh cycle.
                $table->string('cycle', 16);
                $table->unsignedSmallInteger('step_day');
                $table->string('stage', 24); // reminder | final_notice | suspend
                $table->string('outcome', 24)->default('done'); // done | skipped
                $table->json('data')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamps();

                $table->unique(['subscription_id', 'cycle', 'stage', 'step_day'], 'dunning_attempts_step_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_attempts');

        if (Schema::hasColumn('subscriptions', 'past_due_at')) {
            Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('past_due_at'));
        }
    }
};
