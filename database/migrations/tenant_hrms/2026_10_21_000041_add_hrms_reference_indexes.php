<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — HRMS reference indexes (additive only, repair-safe). Split out of the core
 * performance-index migration so a tenant that enables HRMS later still gets them.
 *
 * Adds: offboarding_case_tasks.expense_claim_id, payslip_adjustments.reference_id,
 * leave_adjustments.reference_id.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
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
    }

    public function down(): void
    {
        $this->dropNamedIndex('leave_adjustments', 'leave_adjustments_reference_id_index');
        $this->dropNamedIndex('payslip_adjustments', 'payslip_adjustments_reference_id_index');
        $this->dropNamedIndex('offboarding_case_tasks', 'offboarding_case_tasks_expense_claim_id_index');
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
