<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS P5.2 — rotation templates.
 *
 * A rotation is a repeating day-cycle (`cycle` = ordered list of shift ids,
 * null = a day off): "6 days on night, 2 off" is eight slots. Applying it to
 * people over a window materialises ordinary `attendance_rosters` rows, so the
 * day computation and weekly-off readers need no new code path. Shift ids in the
 * JSON are validated by the service (a JSON list cannot carry a foreign key).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_rotations')) {
            return;
        }

        Schema::create('attendance_rotations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('description', 500)->nullable();
            $table->json('cycle');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_rotations');
    }
};
