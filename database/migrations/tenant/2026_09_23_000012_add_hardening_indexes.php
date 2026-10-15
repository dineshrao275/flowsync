<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant Core hardening indexes.
 *
 * The TMS half of this migration now lives with the TMS migrations
 * (`tenant_tms/2026_09_23_000012_add_tms_hardening_indexes.php`) so that a
 * tenant without the TMS product never indexes tables it does not have.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pivot lookups by the non-leftmost column.
        Schema::table('role_user', function (Blueprint $table) {
            $table->index('user_id', 'role_user_user_id_index');
        });

        Schema::table('permission_role', function (Blueprint $table) {
            $table->index('role_id', 'permission_role_role_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('permission_role', function (Blueprint $table) {
            $table->dropIndex('permission_role_role_id_index');
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropIndex('role_user_user_id_index');
        });
    }
};
