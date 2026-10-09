<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS P5.12 — document versioning.
 *
 * A replacement is a new row: `version` counts up, `supersedes_id` points at
 * the row it replaced, and only the newest row is `is_current` (lists and
 * expiry warnings read that flag). Existing rows become version 1 and current
 * through the column defaults.
 *
 * `supersedes_id` is added with raw SQL, not `constrained()`: a foreign key
 * inside `Schema::table()` rebuilds the table through a temp copy on SQLite and
 * silently drops the enum CHECK constraints (see AGENTS.md pitfalls). The
 * statement is grammar-quoted (never backticks) and every step is guarded so
 * `tenants:provision` can re-run it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_documents')) {
            return;
        }

        if (! Schema::hasColumn('employee_documents', 'version')) {
            Schema::table('employee_documents', function (Blueprint $table) {
                $table->unsignedSmallInteger('version')->default(1);
            });
        }

        if (! Schema::hasColumn('employee_documents', 'is_current')) {
            Schema::table('employee_documents', function (Blueprint $table) {
                $table->boolean('is_current')->default(true);
            });
        }

        if (! Schema::hasColumn('employee_documents', 'supersedes_id')) {
            $grammar = Schema::getConnection()->getQueryGrammar();
            $table = $grammar->wrapTable('employee_documents');
            $column = $grammar->wrap('supersedes_id');

            DB::statement("alter table {$table} add column {$column} integer null references {$table} (id) on delete set null");
            Schema::table('employee_documents', function (Blueprint $table) {
                $table->index('supersedes_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_documents')) {
            return;
        }

        if (Schema::hasColumn('employee_documents', 'supersedes_id')) {
            Schema::table('employee_documents', fn (Blueprint $table) => $table->dropIndex(['supersedes_id']));
            Schema::table('employee_documents', fn (Blueprint $table) => $table->dropColumn('supersedes_id'));
        }

        foreach (['is_current', 'version'] as $column) {
            if (Schema::hasColumn('employee_documents', $column)) {
                Schema::table('employee_documents', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
