<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git\Webhook;

use App\Jobs\SyncGitInstallation;
use App\Models\GitConnection;
use App\Services\Integrations\Git\ProjectRepositoryService;
use App\Services\PlatformAudit;
use Illuminate\Support\Facades\Cache;

/** `installation` events: GitHub tells us the app was removed, suspended, resumed or re-permissioned. */
class InstallationHandler
{
    public function __construct(private readonly PlatformAudit $audit, private readonly ProjectRepositoryService $links) {}

    /** @param  array<string, mixed>  $payload */
    public function handle(GitConnection $connection, string $action, array $payload): string
    {
        $applied = match ($action) {
            'deleted' => $this->revoke($connection),
            'suspend' => $this->suspend($connection),
            'unsuspend' => $this->resume($connection),
            'new_permissions_accepted' => $this->repermission($connection, (array) ($payload['installation']['permissions'] ?? [])),
            default => false,
        };

        return $applied ? GitWebhookProcessor::PROCESSED : GitWebhookProcessor::IGNORED;
    }

    private function revoke(GitConnection $connection): bool
    {
        $connection->forceFill([
            'status' => GitConnection::STATUS_REVOKED, 'revoked_at' => now(), 'access_token' => null, 'refresh_token' => null,
        ])->save();
        Cache::forget("git:token:{$connection->installation_id}");
        // Runs inside the tenant DB (ProcessGitWebhook), where the project links live.
        $this->links->pauseForConnection($connection->uuid);
        $this->audit->record(null, 'git.revoked_by_provider', 'git_connection', $connection->id, [
            'tenant_id' => $connection->tenant_id, 'account' => $connection->account_login,
        ]);

        return true;
    }

    private function suspend(GitConnection $connection): bool
    {
        $connection->forceFill(['status' => GitConnection::STATUS_SUSPENDED, 'suspended_at' => now()])->save();

        return true;
    }

    private function resume(GitConnection $connection): bool
    {
        if (! empty(((array) $connection->settings)['disabled_by_platform'])) {
            return false;   // paused by platform staff: only they can turn it back on
        }

        $connection->forceFill(['status' => GitConnection::STATUS_ACTIVE, 'suspended_at' => null])->save();
        SyncGitInstallation::dispatch($connection->id);

        return true;
    }

    /** @param  array<string, mixed>  $permissions */
    private function repermission(GitConnection $connection, array $permissions): bool
    {
        $connection->forceFill(['scopes' => $permissions])->save();
        SyncGitInstallation::dispatch($connection->id);

        return true;
    }
}
