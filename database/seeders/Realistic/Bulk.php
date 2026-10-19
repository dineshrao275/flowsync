<?php

namespace Database\Seeders\Realistic;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batched inserts with caller-assigned primary keys.
 *
 * Tenant databases are empty when seeded, so the seeder allocates ids itself and wires every foreign key in memory —
 * no `lastInsertId()` arithmetic and no N+1 lookups. `syncSequences()` then moves each PostgreSQL sequence past the
 * highest id so the application's own inserts keep working afterwards.
 */
final class Bulk
{
    /** @var array<string, int> */
    private array $next = [];

    /** @var list<string> */
    private array $touched = [];

    public function __construct(private readonly ?string $connection = null) {}

    /** The next free id for a table (continues after anything already there, so reruns on a half-seeded DB are safe). */
    public function id(string $table): int
    {
        if (! isset($this->next[$table])) {
            $this->next[$table] = ((int) $this->db()->table($table)->max('id')) + 1;
        }

        return $this->next[$table]++;
    }

    /** Reserve `$count` consecutive ids; returns the first. */
    public function reserve(string $table, int $count): int
    {
        $first = $this->id($table);
        $this->next[$table] = $first + $count;

        return $first;
    }

    /** @param list<array<string, mixed>> $rows all rows must share the same keys */
    public function insert(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $this->touched[$table] = $table;
        $columns = count($rows[0]);
        $chunk = max(1, min(1000, intdiv(60000, max(1, $columns))));

        foreach (array_chunk($rows, $chunk) as $part) {
            $this->db()->table($table)->insert($part);
        }

        return count($rows);
    }

    public function syncSequences(): void
    {
        if ($this->db()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->touched as $table) {
            if (! Schema::connection($this->connection)->hasColumn($table, 'id')) {
                continue;
            }
            $seq = $this->db()->selectOne("select pg_get_serial_sequence(?, 'id') as s", [$table])?->s;
            if ($seq) {
                $this->db()->statement("select setval('{$seq}', greatest((select coalesce(max(id), 1) from \"{$table}\"), 1), (select max(id) is not null from \"{$table}\"))");
            }
        }
    }

    private function db(): Connection
    {
        return DB::connection($this->connection);
    }
}
