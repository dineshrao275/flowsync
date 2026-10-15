<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8.1 - TOTP credential for a platform account (system DB `users`). The same
 * table exists in every tenant DB (tenant migration of the same name) so a
 * secret never leaves the database that owns the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('two_factor_credentials')) {
            return;
        }

        Schema::create('two_factor_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('secret');
            $table->timestamp('confirmed_at')->nullable();
            $table->text('recovery_codes')->nullable();
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_credentials');
    }
};
