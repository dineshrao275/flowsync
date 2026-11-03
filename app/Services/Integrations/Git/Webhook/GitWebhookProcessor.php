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

    /** event => handler for events that change tenant data (task links, activity). */
    private const EVENT_HANDLERS = [
        'pull_request' => PullRequestHandler::class,
        'pull_request_review' => PullRequestHandler::class,
        'push' => PushHandler::class,
        'create' => BranchHandler::class,
        'delete' => BranchHandler::class,
        'release' => ReleaseHandler::class,
        'check_suite' => ChecksHandler::class,
        'check_run' => ChecksHandler::class,
        'status' => ChecksHandler::class,
    ];

    /**
     * Runs inside the tenant's database (the job wraps it), so tenant-side handlers can query directly.
     *
     * @param  array<string, mixed>  $payload
     * @return 'processed'|'ignored'
     */
    public function handle(GitConnection $connection, string $event, array $payload, string $delivery = ''): string
    {
        $action = is_string($payload['action'] ?? null) ? $payload['action'] : '';

        return match ($event) {
            'installation' => $this->installation->handle($connection, $action, $payload),
            'installation_repositories' => $this->catalog->handleSelection($connection, $action, $payload),
            'repository' => $this->catalog->handleRepository($connection, $action, $payload),
            default => isset(self::EVENT_HANDLERS[$event])
                ? app(self::EVENT_HANDLERS[$event])->handle($connection, $event, $action, $payload, $delivery)
                : self::IGNORED,
        };
    }
}
