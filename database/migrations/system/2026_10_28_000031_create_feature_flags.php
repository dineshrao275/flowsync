<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P8.7 - runtime feature flags (global switch + percentage rollout) with per-tenant overrides. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('feature_flags')) {
            Schema::create('feature_flags', function (Blueprint $table) {
                $table->id();
                $table->string('key', 100)->unique();
                $table->string('description', 255)->nullable();
                $table->boolean('enabled')->default(false);
                $table->unsignedTinyInteger('rollout_percent')->default(100);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('flag_overrides')) {
            Schema::create('flag_overrides', function (Blueprint $table) {
                $table->id();
                $table->foreignId('feature_flag_id')->constrained('feature_flags')->cascadeOnDelete();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->boolean('enabled');
                $table->timestamps();
                $table->unique(['feature_flag_id', 'tenant_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flag_overrides');
        Schema::dropIfExists('feature_flags');
    }
};
