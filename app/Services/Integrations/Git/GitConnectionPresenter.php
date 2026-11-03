<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use App\Models\GitConnection;

/** The tenant-facing shape of a connection. Credentials and the numeric installation id never appear. */
final class GitConnectionPresenter
{
    /** @param  array{last_delivery_at?: ?string, failed_24h?: int}  $health
     * @return array<string, mixed> */
    public static function present(GitConnection $connection, array $health = []): array
    {
        return [
            'uuid' => $connection->uuid,
            'provider' => $connection->provider,
            'account_login' => $connection->account_login,
            'account_type' => $connection->account_type,
            'status' => $connection->status,
            'repositories_count' => (int) ($connection->repositories_count ?? 0),
            'connected_by_name' => $connection->connected_by_name,
            'connected_at' => $connection->created_at?->toIso8601String(),
            'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
            'last_delivery_at' => $health['last_delivery_at'] ?? null,
            'failed_deliveries_24h' => $health['failed_24h'] ?? 0,
        ];
    }
}
