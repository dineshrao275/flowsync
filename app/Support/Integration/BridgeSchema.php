<?php

namespace App\Support\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the current tenant database has the cross-product bridge tables.
 *
 * `hrms_task_links` (and `goal_task_links`) FK across the TMS and HRMS schemas, so
 * they exist only for a tenant with BOTH products. Core code that surfaces a task
 * link — the HR home, goal evidence, checklist conversion — must ask first. Remembered
 * per database for the process.
 */
final class BridgeSchema
{
    /** @var array<string, bool> */
    private static array $memo = [];

    public static function present(): bool
    {
        $connection = DB::connection();
        $key = $connection->getName().'|'.$connection->getDatabaseName();

        return self::$memo[$key] ??= Schema::hasTable('hrms_task_links');
    }

    public static function flush(): void
    {
        self::$memo = [];
    }
}
