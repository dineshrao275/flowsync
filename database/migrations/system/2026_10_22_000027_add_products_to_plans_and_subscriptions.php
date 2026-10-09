<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FB-4: HRMS and TMS are sold separately. A plan belongs to one product
 * (`tms`, `hrms`) or is a legacy `suite` covering both; a tenant holds at most one
 * subscription per product, so the unique key moves from tenant to (tenant, product).
 * Existing rows are all `suite` and keep working untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subscription_plans', 'product')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                $table->string('product', 16)->default('suite')->index();
            });
        }

        if (! Schema::hasColumn('subscriptions', 'product')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->string('product', 16)->default('suite');
            });

            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropUnique(['tenant_id']);
            });
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->unique(['tenant_id', 'product']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'product']);
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unique('tenant_id');
            $table->dropColumn('product');
        });
        Schema::table('subscription_plans', fn (Blueprint $t) => $t->dropColumn('product'));
    }
};
