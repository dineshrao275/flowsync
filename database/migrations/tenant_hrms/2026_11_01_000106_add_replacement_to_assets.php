<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS P5.16 — asset replacement state.
 *
 * An asset that has been replaced keeps its row (history, serial, invoice) and
 * points at the asset that took over: `replaced_by_asset_id`, with the reason
 * and the time. `replaced_by_asset_id` is added with raw SQL, not
 * `constrained()` — a foreign key inside `Schema::table()` rebuilds the table on
 * SQLite and drops the enum CHECK constraints (AGENTS.md pitfall). Statements
 * are grammar-quoted and every step is guarded for `tenants:provision` re-runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assets')) {
            return;
        }

        if (! Schema::hasColumn('assets', 'replaced_by_asset_id')) {
            $grammar = Schema::getConnection()->getQueryGrammar();
            $table = $grammar->wrapTable('assets');
            $column = $grammar->wrap('replaced_by_asset_id');

            DB::statement("alter table {$table} add column {$column} integer null references {$table} (id) on delete set null");
            Schema::table('assets', function (Blueprint $table) {
                $table->index('replaced_by_asset_id');
            });
        }

        foreach (['replacement_reason', 'replaced_at'] as $column) {
            if (Schema::hasColumn('assets', $column)) {
                continue;
            }

            Schema::table('assets', function (Blueprint $table) use ($column) {
                $column === 'replaced_at'
                    ? $table->timestamp('replaced_at')->nullable()
                    : $table->string('replacement_reason', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('assets')) {
            return;
        }

        if (Schema::hasColumn('assets', 'replaced_by_asset_id')) {
            Schema::table('assets', fn (Blueprint $table) => $table->dropIndex(['replaced_by_asset_id']));
            Schema::table('assets', fn (Blueprint $table) => $table->dropColumn('replaced_by_asset_id'));
        }

        foreach (['replacement_reason', 'replaced_at'] as $column) {
            if (Schema::hasColumn('assets', $column)) {
                Schema::table('assets', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
