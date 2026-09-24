<?php

namespace App\Models\Concerns;

use App\Support\TenantDatabaseManager;

/**
 * Pins a model to the central/system database.
 *
 * Isolated mode (the only mode since Phase 13): resolves to the 'system' connection
 * (config tenancy.system.connection), regardless of which tenant database the
 * request currently uses as its default.
 */
trait CentralConnection
{
    public function getConnectionName(): string
    {
        return app(TenantDatabaseManager::class)->centralConnectionName();
    }
}
