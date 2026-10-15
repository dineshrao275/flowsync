<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'billing_customer_id')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->string('billing_customer_id', 255)->nullable()->index();
            });
        }

        if (! Schema::hasColumn('subscription_plans', 'stripe_price_id')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                // The Stripe Price this plan sells; re-created when amount/currency/cycle change.
                $table->string('stripe_price_id', 255)->nullable();
                $table->string('stripe_price_key', 64)->nullable();
            });
        }

        if (! Schema::hasColumn('subscriptions', 'provider_subscription_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->string('provider_subscription_id', 255)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn('provider_subscription_id'));
        Schema::table('subscription_plans', fn (Blueprint $t) => $t->dropColumn(['stripe_price_id', 'stripe_price_key']));
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('billing_customer_id'));
    }
};
