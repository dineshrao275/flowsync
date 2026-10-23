<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Security\PlatformAccess;
use Illuminate\Console\Command;

/**
 * Sync the platform permission catalog + persona grants from
 * config/platform_access.php. Additive (new grants attach, nothing is ever
 * revoked) — the repair path for installs migrated before a permission
 * existed, since the seed migration runs only once.
 */
class SyncPlatformAccess extends Command
{
    protected $signature = 'platform:sync-access';

    protected $description = 'Sync the platform permission catalog and persona grants from config (additive)';

    public function handle(PlatformAccess $access): int
    {
        $access->syncCatalog();
        $this->info('Platform access catalog synced.');

        return self::SUCCESS;
    }
}
