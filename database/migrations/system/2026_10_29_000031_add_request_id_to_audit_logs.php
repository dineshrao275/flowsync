<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.2: stamp the request correlation id on platform audit rows so a row can
 * be tied to the log lines and the X-Request-Id a user quoted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs') || Schema::hasColumn('audit_logs', 'request_id')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('request_id', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('audit_logs', 'request_id')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropColumn('request_id');
        });
    }
};
