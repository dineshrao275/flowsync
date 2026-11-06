<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two partial indexes for the hottest task reads, validated with EXPLAIN ANALYZE on a scratch
 * copy holding 300k tasks (15k per project): a board column page 9.6 ms -> 0.3 ms, the
 * dashboard's "my open / overdue" 3.4 ms -> 0.4 ms. Each costs about 8-12 MB per 300k tasks.
 *
 *   tasks_project_status_position_idx  (project_id, status_id, position) where parent_id is null
 *       -> a column's first page without scanning the project (board, per-column limits)
 *   tasks_assignee_open_due_idx        (assignee_id, due_date, id) where open (not completed/archived)
 *       -> dashboard "my open", overdue and due-soon lists and counts
 *
 * CREATE INDEX CONCURRENTLY on PostgreSQL (no write lock while a big tenant builds); plain
 * CREATE INDEX on sqlite. Guarded so a repair run is a no-op. Rollback drops both.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'tasks_project_status_position_idx' => ['columns' => ['project_id', 'status_id', 'position'], 'where' => ['parent_id IS NULL']],
        'tasks_assignee_open_due_idx' => ['columns' => ['assignee_id', 'due_date', 'id'], 'where' => ['completed_at IS NULL', 'archived_at IS NULL']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        $grammar = Schema::getConnection()->getQueryGrammar();
        $concurrently = Schema::getConnection()->getDriverName() === 'pgsql' ? ' CONCURRENTLY' : '';

        foreach (self::INDEXES as $name => $spec) {
            if (Schema::hasIndex('tasks', $name) || ! $this->columnsExist($spec)) {
                continue;
            }

            DB::statement(sprintf(
                'CREATE INDEX%s IF NOT EXISTS %s ON %s (%s) WHERE %s',
                $concurrently,
                $grammar->wrap($name),
                $grammar->wrap('tasks'),
                implode(', ', array_map(fn (string $column) => $grammar->wrap($column), $spec['columns'])),
                implode(' AND ', array_map(fn (string $clause) => $this->wrapClause($clause, $grammar), $spec['where'])),
            ));
        }
    }

    public function down(): void
    {
        $grammar = Schema::getConnection()->getQueryGrammar();
        $concurrently = Schema::getConnection()->getDriverName() === 'pgsql' ? ' CONCURRENTLY' : '';

        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement(sprintf('DROP INDEX%s IF EXISTS %s', $concurrently, $grammar->wrap($name)));
        }
    }

    /** @param array{columns: list<string>, where: list<string>} $spec */
    private function columnsExist(array $spec): bool
    {
        $needed = $spec['columns'];

        foreach ($spec['where'] as $clause) {
            $needed[] = strtok($clause, ' ');
        }

        foreach ($needed as $column) {
            if (! Schema::hasColumn('tasks', $column)) {
                return false;
            }
        }

        return true;
    }

    /** "col IS NULL" -> "<wrapped col> IS NULL"; the clauses above are constants, never user input. */
    private function wrapClause(string $clause, $grammar): string
    {
        [$column, $rest] = explode(' ', $clause, 2);

        return $grammar->wrap($column).' '.$rest;
    }
};
