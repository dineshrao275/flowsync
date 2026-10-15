<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P8.1 - TOTP credential for a tenant user; mirrors the system-DB table of the same name. */
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
