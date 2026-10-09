<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS P5.15 — break punches.
 *
 * `kind` separates work punches from break punches: a break starts with an
 * `out` and ends with an `in` of kind `break`. A plain nullable-free string with
 * a default — validated in the application — so SQLite takes a native ADD COLUMN
 * (no table rebuild, no lost CHECK constraints on the enum columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_punches') && ! Schema::hasColumn('attendance_punches', 'kind')) {
            Schema::table('attendance_punches', function (Blueprint $table) {
                $table->string('kind', 16)->default('work');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('attendance_punches') && Schema::hasColumn('attendance_punches', 'kind')) {
            Schema::table('attendance_punches', fn (Blueprint $table) => $table->dropColumn('kind'));
        }
    }
};
