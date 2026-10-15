<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8.6 - tamper evidence for the platform audit trail. Every `audit_logs` row is
 * sealed with hash = sha256(prev_hash | canonical row), forming a chain by id.
 * Retention pruning leaves a tombstone (id + hash) so a legitimately removed row
 * does not read as a break. Pre-existing rows stay unsealed until
 * `audit:chain --seal-legacy`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('audit_logs', 'prev_hash')) {
                    $table->string('prev_hash', 64)->nullable();
                }
                if (! Schema::hasColumn('audit_logs', 'hash')) {
                    $table->string('hash', 64)->nullable();
                }
            });
        }

        if (! Schema::hasTable('audit_log_tombstones')) {
            Schema::create('audit_log_tombstones', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('audit_log_id')->unique();
                $table->string('prev_hash', 64)->nullable();
                $table->string('hash', 64);
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('reason', 40)->default('retention');
                $table->timestamp('pruned_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log_tombstones');

        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                foreach (['hash', 'prev_hash'] as $column) {
                    if (Schema::hasColumn('audit_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
