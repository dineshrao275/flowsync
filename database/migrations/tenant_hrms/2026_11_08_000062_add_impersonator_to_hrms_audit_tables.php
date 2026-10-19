<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F0.3: attribute HRMS ledger rows written during an impersonated session to
 * the platform super admin behind it (central user id, no FK). Repair-safe.
 */
return new class extends Migration
{
    private const TABLES = ['hrms_audit_logs', 'hrms_data_access_logs'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name) || Schema::hasColumn($name, 'impersonator_id')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('impersonator_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'impersonator_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('impersonator_id');
                });
            }
        }
    }
};
