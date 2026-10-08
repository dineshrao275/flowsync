<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Performance indexes (additive only, repair-safe).
 *
 * Adds:
 *   1. tasks(status_id, position) covering index for board column sort.
 *   2. offboarding_case_tasks.expense_claim_id — bare nullable int.
 *   3. payslip_adjustments.reference_id
 *   4. leave_adjustments.reference_id
 *   5. Optional pg_trgm GIN indexes (ENABLE_TRGM / tenancy.tenant.enable_trgm).
 *
 * Notifications already carry (user_id, read_at, created_at); attendance_days
 * already unique(employee_id, work_date); approvals already index
 * (approvable_type, approvable_id). Those are asserted in DBPerformanceTest,
 * not re-created here.
 *
 * PostgreSQL CREATE INDEX CONCURRENTLY cannot run inside a transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->addIndex('tasks', 'tasks_status_id_position_index', ['status_id', 'position'], concurrently: true);

        $this->addIndex(
            'offboarding_case_tasks',
            'offboarding_case_tasks_expense_claim_id_index',
            ['expense_claim_id'],
        );

        $this->addIndex(
            'payslip_adjustments',
            'payslip_adjustments_reference_id_index',
            ['reference_id'],
        );

        $this->addIndex(
            'leave_adjustments',
            'leave_adjustments_reference_id_index',
            ['reference_id'],
        );

        $this->addTrigramIndexes();
    }

    public function down(): void
    {
        $this->dropNamedIndex('tasks', 'tasks_title_trgm');
        $this->dropNamedIndex('tasks', 'tasks_description_trgm');
        $this->dropNamedIndex('users', 'users_email_trgm');
        $this->dropNamedIndex('leave_adjustments', 'leave_adjustments_reference_id_index');
        $this->dropNamedIndex('payslip_adjustments', 'payslip_adjustments_reference_id_index');
        $this->dropNamedIndex('offboarding_case_tasks', 'offboarding_case_tasks_expense_claim_id_index');
        $this->dropNamedIndex('tasks', 'tasks_status_id_position_index');
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndex(string $table, string $name, array $columns, bool $concurrently = false): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        if ($concurrently && $this->isPostgres()) {
            $quoted = implode(', ', array_map(fn (string $column) => $this->wrap($column), $columns));
            DB::statement(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)',
                $this->wrap($name),
                $this->wrap($table),
                $quoted,
            ));

            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name, $columns) {
            $table->index($columns, $name);
        });
    }

    private function addTrigramIndexes(): void
    {
        if (! $this->isPostgres() || ! config('tenancy.tenant.enable_trgm')) {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $this->addGinTrigram('tasks', 'tasks_title_trgm', 'title');
        $this->addGinTrigram('tasks', 'tasks_description_trgm', 'description');
        $this->addGinTrigram('users', 'users_email_trgm', 'email');
    }

    private function addGinTrigram(string $table, string $name, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || $this->indexExists($table, $name)) {
            return;
        }

        DB::statement(sprintf(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s USING gin (%s gin_trgm_ops)',
            $this->wrap($name),
            $this->wrap($table),
            $this->wrap($column),
        ));
    }

    private function dropNamedIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $name)) {
            return;
        }

        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS '.$this->wrap($name));

            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name) {
            $table->dropIndex($name);
        });
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function wrap(string $value): string
    {
        return Schema::getConnection()->getQueryGrammar()->wrap($value);
    }

    private function indexExists(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? '') === $indexName) {
                return true;
            }
        }

        return false;
    }
};
