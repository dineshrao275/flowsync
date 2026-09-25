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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('password');
        });

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

        // At most one default per tenant DB (supported by sqlite and postgres).
        DB::statement('CREATE UNIQUE INDEX users_default_unique ON users (is_default) WHERE is_default = 1');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_default_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
