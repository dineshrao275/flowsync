<?php

namespace App\Support\Hrms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the current tenant database has the HRMS tables.
 *
 * They are created lazily, the first time a tenant gets the HRMS product (FB-4), so core
 * code that touches `employees` — creating a login, signing in, resolving a reporting
 * line — must ask first instead of assuming. Remembered per database for the process.
 */
final class HrmsSchema
{
    /** @var array<string, bool> */
    private static array $memo = [];

    public static function present(): bool
    {
        $connection = DB::connection();
        $key = $connection->getName().'|'.$connection->getDatabaseName();

        return self::$memo[$key] ??= Schema::hasTable('employees');
    }

    public static function flush(): void
    {
        self::$memo = [];
    }
}
