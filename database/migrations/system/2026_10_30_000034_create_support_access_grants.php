<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8.4 - consent-based support access. A tenant admin grants the platform team a
 * time-boxed (optionally read-only) window; impersonation can be bound to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_access_grants')) {
            Schema::create('support_access_grants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                // Tenant-local user id (no FK across databases) + a snapshot for the audit trail.
                $table->unsignedBigInteger('granted_by_user_id');
                $table->string('granted_by_name');
                $table->string('granted_by_email');
                $table->string('mode', 16)->default('read_only');
                $table->text('note');
                $table->unsignedBigInteger('support_ticket_id')->nullable();
                $table->timestamp('expires_at');
                $table->unsignedSmallInteger('session_minutes')->default(60);
                $table->timestamp('revoked_at')->nullable();
                $table->string('revoked_by_name')->nullable();
                $table->unsignedInteger('uses')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'revoked_at', 'expires_at']);
            });
        }

        if (Schema::hasTable('impersonation_logs') && ! Schema::hasColumn('impersonation_logs', 'support_access_grant_id')) {
            Schema::table('impersonation_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('support_access_grant_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('impersonation_logs', 'support_access_grant_id')) {
            Schema::table('impersonation_logs', function (Blueprint $table) {
                $table->dropColumn('support_access_grant_id');
            });
        }
        Schema::dropIfExists('support_access_grants');
    }
};
