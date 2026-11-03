<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a work log came from: null = written in the app, 'api' = through a personal API token (the MCP).
 * HR attendance is derived from work logs, and it must never be derived from time an agent wrote, so the
 * derivation skips `source = 'api'`. Guarded so a repair re-run is a no-op; the builder keeps this a plain
 * ADD COLUMN on both PostgreSQL and sqlite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('work_logs') && ! Schema::hasColumn('work_logs', 'source')) {
            Schema::table('work_logs', function (Blueprint $table) {
                $table->string('source', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('work_logs', 'source')) {
            Schema::table('work_logs', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
