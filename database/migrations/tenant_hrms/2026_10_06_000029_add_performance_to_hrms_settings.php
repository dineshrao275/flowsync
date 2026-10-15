<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — HRMS performance settings (tenant database, P12 follow-up).
 *
 * Adds the `performance` JSON section (`feedback_peer_count`) that the
 * cycle workflow reads when generating peer feedback. The P7.2 `comp_off`
 * / P9 `payroll` precedent: repair-safe column add plus a backfill that
 * only touches rows whose section is null, so a tenant that tuned its
 * peer count keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hrms_settings', 'performance')) {
            Schema::table('hrms_settings', function (Blueprint $table) {
                $table->json('performance')->nullable();
            });
        }

        $defaults = config('hrms.settings_defaults.performance');

        if (is_array($defaults)) {
            DB::table('hrms_settings')
                ->whereNull('performance')
                ->update(['performance' => json_encode($defaults)]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hrms_settings', 'performance')) {
            Schema::table('hrms_settings', function (Blueprint $table) {
                $table->dropColumn('performance');
            });
        }
    }
};
