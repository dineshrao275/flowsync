<?php

namespace App\Support\Tms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the current tenant database has the TMS tables.
 *
 * They are created only for tenants with the TMS product, so core code that touches
 * `tasks`/`projects` — global search, HRMS evidence links, the dashboard — must ask
 * first instead of assuming. Remembered per database for the process.
 */
final class TmsSchema
{
    /** @var array<string, bool> */
    private static array $memo = [];

    public static function present(): bool
    {
        $connection = DB::connection();
        $key = $connection->getName().'|'.$connection->getDatabaseName();

        return self::$memo[$key] ??= Schema::hasTable('tasks');
    }

    public static function flush(): void
    {
        self::$memo = [];
    }
}
