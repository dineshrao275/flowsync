<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant Core performance indexes (additive only, repair-safe).
 *
 * The TMS half (the tasks board/trigram indexes) now lives with the TMS
 * migrations (`tenant_tms/2026_10_13_000036_add_tms_phase2_performance_indexes.php`)
 * so a non-TMS tenant never indexes the tasks table.
 *
 * PostgreSQL CREATE INDEX CONCURRENTLY cannot run inside a transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->addTrigramIndexes();
    }

    public function down(): void
    {
        $this->dropNamedIndex('users', 'users_email_trgm');
    }

    private function addTrigramIndexes(): void
    {
        if (! $this->isPostgres() || ! config('tenancy.tenant.enable_trgm')) {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

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
