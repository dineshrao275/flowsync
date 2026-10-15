<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.2: stamp the request correlation id on the two HRMS ledgers (changes and
 * sensitive reads). Repair-safe: guarded on the table and the column.
 */
return new class extends Migration
{
    private const TABLES = ['hrms_audit_logs', 'hrms_data_access_logs'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name) || Schema::hasColumn($name, 'request_id')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->string('request_id', 64)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name) || ! Schema::hasColumn($name, 'request_id')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['request_id']);
                $table->dropColumn('request_id');
            });
        }
    }
};
