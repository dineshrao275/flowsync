<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H-7: the audit feed now orders and paginates in SQL over the union of
 * `audit_logs` and `impersonation_logs` (`event_at desc, id desc`). The table
 * only carried the `(subject_type, subject_id)` lookup index, so the order-by
 * was a sort of the whole table on every admin page view and every export.
 *
 * Guarded (`hasTable` + `hasIndex`) because `tenants:provision` and repairs
 * re-run system migrations and must land on an arbitrary point.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        if (Schema::hasIndex('audit_logs', ['created_at', 'id'])) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'id']);
        });
    }
};
