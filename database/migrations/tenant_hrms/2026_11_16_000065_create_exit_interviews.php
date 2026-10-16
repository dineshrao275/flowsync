<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F6.6 — one exit interview per exit (offboarding) case. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exit_interviews')) {
            return;
        }

        Schema::create('exit_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offboarding_case_id')->unique()->constrained('offboarding_cases')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('status', 12)->default('pending'); // pending | completed | waived
            $table->json('answers')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exit_interviews');
    }
};
