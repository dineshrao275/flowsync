<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS P5.1/P5.2 — the shift catalogue grows the columns the starter patterns
 * and the admin page need: `is_system` (starters cannot be deleted),
 * `working_days` (weekday slugs the pattern runs on), a free-text description
 * and `segments` (the work blocks of a split shift).
 *
 * Plain nullable/defaulted columns only — no `constrained()`, so SQLite takes a
 * native ADD COLUMN and no CHECK constraint is lost in a table rebuild. Every
 * step is column-guarded: `tenants:provision` re-runs failed migrations.
 */
return new class extends Migration
{
    private const COLUMNS = ['is_system', 'working_days', 'description', 'segments'];

    public function up(): void
    {
        if (! Schema::hasTable('attendance_shifts')) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('attendance_shifts', $column)) {
                continue;
            }

            Schema::table('attendance_shifts', function (Blueprint $table) use ($column) {
                match ($column) {
                    'is_system' => $table->boolean('is_system')->default(false),
                    'working_days' => $table->json('working_days')->nullable(),
                    'description' => $table->string('description', 500)->nullable(),
                    'segments' => $table->json('segments')->nullable(),
                };
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            if (Schema::hasTable('attendance_shifts') && Schema::hasColumn('attendance_shifts', $column)) {
                Schema::table('attendance_shifts', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
