<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2.5 — substring search (`ILIKE '%term%'`, see App\Support\Like) is a sequential scan on a large table.
 * On PostgreSQL a pg_trgm GIN index makes it index-assisted. SQLite (the test fast-path) and a database that
 * will not let the owner create the extension skip this silently — search then works as before, just slower.
 */
return new class extends Migration
{
    private const INDEXES = [
        'tasks_title_trgm' => ['tasks', 'title'],
        'tasks_key_trgm' => ['tasks', '"key"'],
        'comments_body_trgm' => ['comments', 'comment'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('create extension if not exists pg_trgm');
        } catch (Throwable) {
            return;
        }

        foreach (self::INDEXES as $name => [$table, $column]) {
            if (Schema::hasTable($table)) {
                DB::statement("create index if not exists $name on $table using gin ($column gin_trgm_ops)");
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("drop index if exists $name");
        }
    }
};
