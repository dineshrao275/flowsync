<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R2: roles that ship in config/permissions.php are SYSTEM roles — owned by the
 * product, evolved by provisioning, read-only through the API. A tenant that
 * wants a variant clones one into a custom role instead of editing it (an edit
 * to a system role was, until now, silently reverted by the next repair).
 *
 * Repair-safe: the column is added only if missing, and the backfill runs every
 * time, so a tenant that already has the column but predates a newer default
 * role still gets it flagged. The flag is matched by slug against the config.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        if (! Schema::hasColumn('roles', 'is_system')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->boolean('is_system')->default(false);
            });
        }

        $slugs = array_keys(config('permissions.roles', []));

        if ($slugs !== []) {
            DB::table('roles')->whereIn('slug', $slugs)->update(['is_system' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('roles') && Schema::hasColumn('roles', 'is_system')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('is_system');
            });
        }
    }
};
