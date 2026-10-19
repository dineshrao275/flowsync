<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subscription_plans', 'stripe_product_id')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                // One Stripe Product per plan (prices are immutable, so a new price is created on the same product).
                $table->string('stripe_product_id', 255)->nullable();
                $table->timestamp('stripe_synced_at')->nullable();
                $table->text('stripe_sync_error')->nullable();
            });
        }

        if (! Schema::hasColumn('subscriptions', 'pending_plan_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                // A plan change waits for the end of the paid period: no overlap, no second charge.
                $table->unsignedBigInteger('pending_plan_id')->nullable();
                $table->timestamp('pending_change_at')->nullable()->index();
                $table->timestamp('pending_requested_at')->nullable();
                $table->string('provider_schedule_id', 255)->nullable();
            });
        }

        if (! Schema::hasTable('plan_price_changes')) {
            Schema::create('plan_price_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
                $table->integer('old_price_cents');
                $table->integer('new_price_cents');
                $table->string('currency', 3);
                $table->string('billing_cycle', 16);
                $table->string('old_stripe_price_id', 255)->nullable();
                $table->string('new_stripe_price_id', 255)->nullable();
                $table->unsignedSmallInteger('notice_days');
                $table->timestamp('announced_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subscription_price_notices')) {
            Schema::create('subscription_price_notices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_price_change_id')->constrained('plan_price_changes')->cascadeOnDelete();
                $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->integer('old_amount_cents');
                $table->integer('new_amount_cents');
                $table->string('currency', 3);
                // The first renewal at least `notice_days` after the announcement: when the new amount starts to apply.
                $table->timestamp('effective_at');
                $table->string('status', 16)->default('pending'); // pending | applied | cancelled
                $table->timestamp('notified_at')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();

                $table->unique(['plan_price_change_id', 'subscription_id']);
                $table->index(['status', 'effective_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_price_notices');
        Schema::dropIfExists('plan_price_changes');
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn(['pending_plan_id', 'pending_change_at', 'pending_requested_at', 'provider_schedule_id']));
        Schema::table('subscription_plans', fn (Blueprint $t) => $t->dropColumn(['stripe_product_id', 'stripe_synced_at', 'stripe_sync_error']));
    }
};
