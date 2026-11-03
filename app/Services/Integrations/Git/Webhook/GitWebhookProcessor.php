<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git\Webhook;

use App\Models\GitConnection;

/**
 * Routes one verified delivery to the handler for its event. Later phases register the pull request,
 * push, release and check handlers here; an event with no handler is simply ignored, never an error.
 */
class GitWebhookProcessor
{
    public const PROCESSED = 'processed';

    public const IGNORED = 'ignored';

    public function __construct(
        private readonly InstallationHandler $installation,
        private readonly RepositoryCatalogHandler $catalog,
    ) {}

    /**
     * Runs inside the tenant's database (the job wraps it), so tenant-side handlers can query directly.
     *
     * @param  array<string, mixed>  $payload
     * @return 'processed'|'ignored'
     */
    public function handle(GitConnection $connection, string $event, array $payload): string
    {
        $action = is_string($payload['action'] ?? null) ? $payload['action'] : '';

        return match ($event) {
            'installation' => $this->installation->handle($connection, $action, $payload),
            'installation_repositories' => $this->catalog->handleSelection($connection, $action, $payload),
            'repository' => $this->catalog->handleRepository($connection, $action, $payload),
            default => self::IGNORED,
        };
    }
}
