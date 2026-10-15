<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R7/R8: an impersonation session now records WHY it was opened, whether it may
 * write, and when the server will end it. Existing rows keep NULLs (they predate
 * the controls) — `mode` defaults to the safe value for anything new.
 *
 * Guarded per column: repairs re-run system migrations at arbitrary points.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('impersonation_logs')) {
            return;
        }

        Schema::table('impersonation_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('impersonation_logs', 'reason')) {
                $table->text('reason')->nullable();
            }
            if (! Schema::hasColumn('impersonation_logs', 'mode')) {
                $table->string('mode', 16)->default('read_only');
            }
            if (! Schema::hasColumn('impersonation_logs', 'expires_at')) {
                $table->timestamp('expires_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('impersonation_logs')) {
            return;
        }

        Schema::table('impersonation_logs', function (Blueprint $table) {
            foreach (['reason', 'mode', 'expires_at'] as $column) {
                if (Schema::hasColumn('impersonation_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
