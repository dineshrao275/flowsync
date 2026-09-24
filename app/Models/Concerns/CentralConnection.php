<?php

namespace App\Models\Concerns;

use App\Support\TenantDatabaseManager;

/**
 * Pins a model to the central/system database.
 *
 * Shared mode  (TENANCY_DRIVER=shared): resolves to the single default database,
 *               so central models behave exactly as today.
 * Isolated mode (TENANCY_DRIVER=isolated): resolves to the 'system' connection
 *               (config tenancy.system.connection), regardless of which tenant
 *               database the request currently uses as its default.
 */
trait CentralConnection
{
    public function getConnectionName(): string
    {
        return app(TenantDatabaseManager::class)->centralConnectionName();
    }
}
