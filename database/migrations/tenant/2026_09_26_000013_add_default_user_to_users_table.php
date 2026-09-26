<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks one user per tenant DB as the "default" user: the account that can
 * never be deleted and that a super admin can fall back to. Shiftable by a
 * tenant admin or a super admin, but only onto another admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Repair-safe: a previously failed run of this migration may have added
        // the column (or index) before aborting, and `tenants:provision` re-runs
        // unrecorded migrations.
        if (! Schema::hasColumn('users', 'is_default')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_default')->default(false)->after('password');
            });
        }

        // Backfill: the oldest admin of an existing tenant becomes the default,
        // falling back to the oldest user when no admin role exists yet.
        $adminRoleId = DB::table('roles')->where('slug', 'admin')->value('id');

        $candidateId = $adminRoleId
            ? DB::table('role_user')->where('role_id', $adminRoleId)->min('user_id')
            : null;

        $candidateId ??= DB::table('users')->min('id');

        if ($candidateId) {
            DB::table('users')->where('id', $candidateId)->update(['is_default' => true]);
        }

        // At most one default per tenant DB. The predicate must use `true`, not
        // `1`: `is_default` is a boolean column and PostgreSQL rejects
        // `boolean = integer` (sqlite accepts either).
        DB::statement('DROP INDEX IF EXISTS users_default_unique');
        DB::statement('CREATE UNIQUE INDEX users_default_unique ON users (is_default) WHERE is_default = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_default_unique');

        if (Schema::hasColumn('users', 'is_default')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_default');
            });
        }
    }
};
