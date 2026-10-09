<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — HRMS comp-off settings (tenant database, P7 follow-up).
 *
 * Adds the `comp_off` JSON section (`from_weekends`, `from_holidays`,
 * `validity_months`) that `CompOffService` reads per accrual. It ships as a
 * follow-up rather than inside `000020` because the catalogue migration
 * owns tables, not the shared settings row — and because P7.2 references
 * the keys while P7.1's sketch predates them.
 *
 * Numbering (D2.7): the next unused NN in P7's `2026_09_29` date block is
 * 22. P9's `2026_09_30_000022` shares the NN but not the filename, so the
 * migrator never confuses them; the date prefix keeps the phases ordered.
 *
 * Repair-safe: the column is added only when absent, and the backfill only
 * touches rows whose section is null — a tenant that tuned its validity
 * window keeps it. `down()` drops the column: `hrms_settings` carries no
 * CHECK constraints (no enum columns), so the SQLite table rebuild drops
 * nothing else with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hrms_settings', 'comp_off')) {
            Schema::table('hrms_settings', function (Blueprint $table) {
                $table->json('comp_off')->nullable();
            });
        }

        $defaults = config('hrms.settings_defaults.comp_off');

        if (is_array($defaults)) {
            DB::table('hrms_settings')
                ->whereNull('comp_off')
                ->update(['comp_off' => json_encode($defaults)]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hrms_settings', 'comp_off')) {
            Schema::table('hrms_settings', function (Blueprint $table) {
                $table->dropColumn('comp_off');
            });
        }
    }
};
