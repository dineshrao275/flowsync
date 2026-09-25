<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('status', 32)->default('active')->after('description');
            $table->string('provisioning_status', 32)->default('provisioned')->after('status');
            $table->text('provisioning_error')->nullable()->after('provisioning_status');
            $table->timestamp('provisioned_at')->nullable()->after('provisioning_error');

            $table->string('db_name')->nullable()->after('provisioned_at');
            $table->string('db_host')->nullable()->after('db_name');
            $table->string('db_port', 10)->nullable()->after('db_host');
            $table->text('db_user')->nullable()->after('db_port');
            $table->text('db_password')->nullable()->after('db_user');

            $table->unsignedBigInteger('subscription_id')->nullable()->after('db_password');
            $table->string('billing_email')->nullable()->after('subscription_id');
            $table->string('contact_name')->nullable()->after('billing_email');
            $table->string('contact_email')->nullable()->after('contact_name');
            $table->timestamp('trial_ends_at')->nullable()->after('contact_email');

            $table->json('limits_override')->nullable()->after('trial_ends_at');
            $table->json('features_override')->nullable()->after('limits_override');
            $table->json('onboarding_meta')->nullable()->after('features_override');
            $table->json('settings')->nullable()->after('onboarding_meta');

            $table->softDeletes();
            $table->index('status', 'tenants_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $columns = [
                'status', 'provisioning_status', 'provisioning_error', 'provisioned_at',
                'db_name', 'db_host', 'db_port', 'db_user', 'db_password', 'subscription_id',
                'billing_email', 'contact_name', 'contact_email', 'trial_ends_at',
                'limits_override', 'features_override', 'onboarding_meta', 'settings',
            ];

            $table->dropIndex('tenants_status_index');
            $table->dropColumn($columns);
            $table->dropSoftDeletes();
        });
    }
};
