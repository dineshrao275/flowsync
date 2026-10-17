<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M-E9 — link types beyond blocks / related_to: `duplicates` and `clones`. `type` was created as an enum, which
 * is a CHECK constraint on both PostgreSQL and SQLite that cannot be altered in place, so the table is rebuilt
 * (no other table references it) and its rows copied. Repair-safe: a table that already allows the new values is
 * left alone.
 */
return new class extends Migration
{
    private const TYPES = ['blocks', 'related_to', 'duplicates', 'clones'];

    public function up(): void
    {
        if (! Schema::hasTable('task_dependencies') || Schema::hasTable('task_dependencies_new')) {
            return;
        }

        if ($this->alreadyWide()) {
            return;
        }

        Schema::create('task_dependencies_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->enum('type', self::TYPES)->default('blocks');
            $table->timestamps();
        });

        DB::statement('insert into task_dependencies_new (id, task_id, depends_on_task_id, type, created_at, updated_at) select id, task_id, depends_on_task_id, type, created_at, updated_at from task_dependencies');

        Schema::drop('task_dependencies');
        Schema::rename('task_dependencies_new', 'task_dependencies');

        Schema::table('task_dependencies', function (Blueprint $table) {
            $table->unique(['task_id', 'depends_on_task_id', 'type']);
            $table->index('depends_on_task_id', 'task_dependencies_depends_on_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("select setval(pg_get_serial_sequence('task_dependencies', 'id'), coalesce((select max(id) from task_dependencies), 1))");
        }
    }

    public function down(): void
    {
        // Narrowing the enum again would have to delete the new link types; leaving the wider table is harmless.
    }

    private function alreadyWide(): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $sql = (string) DB::table('sqlite_master')->where('name', 'task_dependencies')->value('sql');

            return str_contains($sql, "'duplicates'");
        }

        if ($driver === 'pgsql') {
            $rows = DB::select("select pg_get_constraintdef(oid) as def from pg_constraint where conrelid = 'task_dependencies'::regclass and contype = 'c'");

            return collect($rows)->contains(fn ($r) => str_contains((string) $r->def, 'duplicates'));
        }

        return false;
    }
};
