<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Git provider installation a tenant has connected (GitHub App).
 * Central because the installation id routes a webhook to a tenant before any
 * tenant database is open, and the credentials must never ride a tenant export.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('git_connections')) {
            return;
        }

        Schema::create('git_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('provider', 20)->default('github');
            $table->unsignedBigInteger('installation_id');
            $table->string('account_login', 120);
            $table->string('account_type', 20)->default('Organization');
            $table->string('auth_type', 20)->default('app');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('settings')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('connected_by')->nullable();
            $table->string('connected_by_name', 120)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // One installation belongs to exactly one tenant.
            $table->unique(['provider', 'installation_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_connections');
    }
};
