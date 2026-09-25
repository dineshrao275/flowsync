<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Login routing index (Phase 12): denormalized mirror of each tenant DB's
        // users, kept in the central/system DB so a login resolves "which tenant,
        // which database, which user row" without scanning every tenant database.
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
        });

        // Provisioning runs (Phase 12): one row per ProvisionTenantJob attempt,
        // giving the Super Admin platform a live provisioning timeline.
        Schema::create('provisioning_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20); // running|succeeded|failed
            $table->string('step', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_runs');
        Schema::dropIfExists('tenant_users');
    }
};
