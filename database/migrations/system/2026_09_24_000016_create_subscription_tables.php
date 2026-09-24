<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 — subscriptions (system database).
 *
 * subscription_plans   — machine-readable catalog entries (config/subscriptions.php),
 *                        JSON `limits` keyed by the catalog (§5.3).
 * subscriptions        — one row per tenant (re-stamped on plan changes); statuses
 *                        trialing|active|past_due|canceled|expired|ended.
 * subscription_events  — append-only audit of plan/status changes (type, from/to plan).
 *
 * `tenants.subscription_id` (a convenience denormalized pointer, declared in 000013)
 * gets its FK on PostgreSQL; the sqlite fast-path can't ALTER ADD CONSTRAINT, so it
 * gets a plain index there (app-level FK via the Subscription model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('billing_cycle', 16)->default('monthly'); // monthly|annual
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->unsignedInteger('trial_duration_days')->nullable();
            $table->json('limits')->nullable(); // {"users":10,"workspaces":3,"modules":["time_tracking"]}
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('status', 16)->default('trialing'); // trialing|active|past_due|canceled|expired|ended
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->unsignedInteger('seats')->default(0);
            $table->string('billing_provider', 64)->nullable();
            $table->string('billing_reference', 255)->nullable();
            $table->timestamps();

            // One active subscription per tenant; plan changes re-stamp this row.
            $table->unique('tenant_id');
        });

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32); // subscribed|plan_changed|renewed|trial_started|trial_expired|canceled|reactivated|paused|payment_failed|seats_changed
            $table->foreignId('from_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('to_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->json('data')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); // system users
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->index('subscription_id', 'tenants_subscription_id_index');
            } else {
                $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->dropIndex('tenants_subscription_id_index');
            } else {
                $table->dropForeign(['subscription_id']);
            }
        });

        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
