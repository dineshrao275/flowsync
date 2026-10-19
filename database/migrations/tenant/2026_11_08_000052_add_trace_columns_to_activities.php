<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F0.3/F0.4: trace every task/project activity back to the request that wrote
 * it (`request_id`) and, for impersonated sessions, to the platform super admin
 * behind it (`impersonator_id`, a central user id — no FK, it lives in another
 * database). Repair-safe: guarded on the table and each column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activities')) {
            return;
        }

        if (! Schema::hasColumn('activities', 'request_id')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->string('request_id', 64)->nullable()->index();
            });
        }

        if (! Schema::hasColumn('activities', 'impersonator_id')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->unsignedBigInteger('impersonator_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('activities')) {
            return;
        }

        if (Schema::hasColumn('activities', 'impersonator_id')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->dropColumn('impersonator_id');
            });
        }

        if (Schema::hasColumn('activities', 'request_id')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->dropIndex(['request_id']);
                $table->dropColumn('request_id');
            });
        }
    }
};
