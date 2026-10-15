<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS Phase 24 — talent calibration columns.
 *
 * Calibration adjusts a review without rewriting history: the manager's
 * original `manager_rating` stays, and the calibrated outcome lands in
 * `calibrated_rating` with who/when. Until now `ReviewStatus::Calibrating`
 * and `::Final` existed in the enum but nothing ever set them — the cycle
 * could enter the calibration stage and then nothing could happen there.
 *
 * This is NOT a composite score column (see the founding rule on
 * `ReviewSummary`): one human-entered 1-5 rating replacing another, with
 * the original preserved beside it.
 *
 * `calibrated_by` is added with raw SQL, not `constrained()`: a foreign key
 * inside `Schema::table()` rebuilds the table through a temp copy on SQLite and
 * silently drops the enum CHECK constraints (see AGENTS.md pitfalls). The
 * statement is grammar-quoted (never backticks) and every step is guarded so
 * `tenants:provision` can re-run it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_summaries')) {
            return;
        }

        if (! Schema::hasColumn('review_summaries', 'calibrated_rating')) {
            Schema::table('review_summaries', function (Blueprint $table) {
                $table->unsignedTinyInteger('calibrated_rating')->nullable();
            });
        }

        if (! Schema::hasColumn('review_summaries', 'calibrated_at')) {
            Schema::table('review_summaries', function (Blueprint $table) {
                $table->timestamp('calibrated_at')->nullable();
            });
        }

        if (! Schema::hasColumn('review_summaries', 'calibrated_by')) {
            $grammar = Schema::getConnection()->getQueryGrammar();
            $table = $grammar->wrapTable('review_summaries');
            $column = $grammar->wrap('calibrated_by');
            $users = $grammar->wrapTable('users');

            DB::statement("alter table {$table} add column {$column} bigint null references {$users} (id) on delete set null");
            Schema::table('review_summaries', function (Blueprint $table) {
                $table->index('calibrated_by');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('review_summaries')) {
            return;
        }

        if (Schema::hasColumn('review_summaries', 'calibrated_by')) {
            Schema::table('review_summaries', fn (Blueprint $table) => $table->dropIndex(['calibrated_by']));
            Schema::table('review_summaries', fn (Blueprint $table) => $table->dropColumn('calibrated_by'));
        }

        foreach (['calibrated_at', 'calibrated_rating'] as $column) {
            if (Schema::hasColumn('review_summaries', $column)) {
                Schema::table('review_summaries', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
