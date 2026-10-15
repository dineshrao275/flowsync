<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — HRMS payroll settings (tenant database, P9 follow-up).
 *
 * Adds the `payroll` JSON section (`ot_rate`) that the payroll engine
 * reads per run. It ships as a follow-up rather than inside `000022`
 * because that migration owns payroll tables, not the shared settings
 * row — and because P9.3 references the keys while P9.1's sketch predates
 * them (the P7.2 `comp_off` precedent).
 *
 * Numbering (D2.7): the 09_30 block holds 000022 (P9.1), reserves 000023
 * for P10, and spent 000024 on compensation — so 25 is next. P11's
 * `2026_10_01_000025` shares the NN but not the filename, so the migrator
 * never confuses them.
 *
 * Repair-safe: the column is added only when absent, and the backfill only
 * touches rows whose section is null — a tenant that tuned its overtime
 * rate keeps it. `down()` drops the column: `hrms_settings` carries no
 * CHECK constraints (no enum columns), so the SQLite table rebuild drops
 * nothing else with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hrms_settings', 'payroll')) {
            Schema::table('hrms_settings', function (Blueprint $table) {
                $table->json('payroll')->nullable();
            });
        }

        $defaults = config('hrms.settings_defaults.payroll');

        if (is_array($defaults)) {
            DB::table('hrms_settings')
                ->whereNull('payroll')
                ->update(['payroll' => json_encode($defaults)]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hrms_settings', 'payroll')) {
            Schema::table('hrms_settings', function (Blueprint $table) {
                $table->dropColumn('payroll');
            });
        }
    }
};
