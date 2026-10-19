<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F10.3 — a webhook endpoint can speak Slack or Microsoft Teams "incoming webhook" instead of the
 * signed JSON envelope. Default `json` keeps every existing endpoint exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webhook_endpoints') && ! Schema::hasColumn('webhook_endpoints', 'format')) {
            Schema::table('webhook_endpoints', function (Blueprint $table) {
                $table->string('format', 12)->default('json');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('webhook_endpoints') && Schema::hasColumn('webhook_endpoints', 'format')) {
            Schema::table('webhook_endpoints', function (Blueprint $table) {
                $table->dropColumn('format');
            });
        }
    }
};
