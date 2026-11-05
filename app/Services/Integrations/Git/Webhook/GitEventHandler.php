<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git\Webhook;

use App\Models\GitConnection;

/** Handles tenant-side provider events (pull requests, pushes, checks...). Runs inside the tenant's database. */
interface GitEventHandler
{
    /**
     * @param  array<string, mixed>  $payload
     * @return 'processed'|'ignored'
     */
    public function handle(GitConnection $connection, string $event, string $action, array $payload, string $delivery): string;
}
