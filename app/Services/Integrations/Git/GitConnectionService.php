<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use App\Jobs\SyncGitInstallation;
use App\Models\GitConnection;
use App\Models\GitWebhookDelivery;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlatformAudit;
use App\Services\TenantLimits;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Owns the connection lifecycle: send an admin to install the app, turn the setup redirect into a
 * connection, list and disconnect. Everything here is keyed by the tenant, so one tenant can never
 * see, claim or remove another's installation.
 */
class GitConnectionService
{
    public const MANAGE_PERMISSION = 'git.manage';

    public function __construct(
        private readonly GitProvider $provider,
        private readonly GitInstallState $state,
        private readonly TenantDatabaseManager $databases,
        private readonly TenantLimits $limits,
        private readonly PlatformAudit $audit,
    ) {}

    public function installUrl(Tenant $tenant, User $user): string
    {
        $this->assertEnabled();

        return $this->provider->installUrl($this->state->issue($tenant->id, $user->id));
    }

    /**
     * Turns GitHub's setup redirect into a connection.
     *
     * The installation id arrives in a query string anyone can edit, so it is only accepted once the
     * installer's own OAuth `code` proves they can see that installation; otherwise a tenant admin
     * could claim another organisation's installation by guessing its (sequential) id.
     *
     * @throws GitConnectionException
     */
    public function handleSetup(string $stateToken, int $installationId, ?string $code, ?Request $request = null): GitConnection
    {
        $this->assertEnabled();
        $claim = $this->state->redeem($stateToken);

        $tenant = Tenant::query()->find($claim['tenant_id']);
        if ($tenant === null || ! $this->limits->hasModule($tenant, 'git') || ! $this->limits->productEnabled($tenant, 'tms')) {
            throw new GitConnectionException(__('Git integration is not available on this plan.'), 403);
        }

        $user = $this->databases->using($tenant, fn () => User::query()->with('roles.permissions')->find($claim['user_id']));
        if ($user === null || ! $user->hasPermission(self::MANAGE_PERMISSION)) {
            throw new GitConnectionException(__('You no longer have permission to connect a Git account.'), 403);
        }

        if ($code === null || $code === '' || ! $this->provider->userCanAccessInstallation($code, $installationId)) {
            throw new GitConnectionException(__('GitHub could not confirm that you own this installation.'), 403);
        }

        $installation = $this->provider->installation($installationId);

        $existing = GitConnection::query()->where('provider', $this->provider->name())->where('installation_id', $installationId)->first();
        if ($existing !== null && $existing->tenant_id !== $tenant->id) {
            throw new GitConnectionException(__('This GitHub installation is already connected to another organization.'), 409);
        }

        $connection = GitConnection::query()->updateOrCreate(
            ['provider' => $this->provider->name(), 'installation_id' => $installationId],
            [
                'tenant_id' => $tenant->id,
                'account_login' => $installation['account']['login'],
                'account_type' => $installation['account']['type'],
                'auth_type' => 'app',
                'scopes' => $installation['permissions'],
                'status' => $installation['suspended_at'] ? GitConnection::STATUS_SUSPENDED : GitConnection::STATUS_ACTIVE,
                'suspended_at' => $installation['suspended_at'],
                'revoked_at' => null,
                'connected_by' => $user->id,
                'connected_by_name' => $user->name,
            ],
        );

        SyncGitInstallation::dispatch($connection->id);
        $this->audit->record($request, 'git.connected', 'git_connection', $connection->id, [
            'tenant_id' => $tenant->id, 'installation_id' => $installationId, 'account' => $connection->account_login,
            'actor_user_id' => $user->id, 'actor_name' => $user->name,
        ]);

        return $connection;
    }

    /** @return Collection<int, GitConnection> */
    public function listForTenant(Tenant $tenant): Collection
    {
        return GitConnection::query()->where('tenant_id', $tenant->id)
            ->where('status', '!=', GitConnection::STATUS_REVOKED)
            ->withCount(['repositories' => fn ($q) => $q->where('available', true)])
            ->orderBy('account_login')->get();
    }

    /**
     * Webhook health per connection id: when GitHub last reached us and how many deliveries failed in 24h.
     *
     * @param  Collection<int, GitConnection>  $connections
     * @return array<int, array{last_delivery_at: ?string, failed_24h: int}>
     */
    public function healthFor(Collection $connections): array
    {
        $ids = $connections->pluck('id')->all();
        $rows = GitWebhookDelivery::query()->whereIn('git_connection_id', $ids)
            ->selectRaw('git_connection_id, max(received_at) as last_at')->groupBy('git_connection_id')->pluck('last_at', 'git_connection_id');
        $failed = GitWebhookDelivery::query()->whereIn('git_connection_id', $ids)->where('status', GitWebhookDelivery::STATUS_FAILED)
            ->where('received_at', '>=', now()->subDay())->selectRaw('git_connection_id, count(*) as n')->groupBy('git_connection_id')->pluck('n', 'git_connection_id');

        return $connections->mapWithKeys(fn (GitConnection $c) => [$c->id => [
            'last_delivery_at' => isset($rows[$c->id]) ? Carbon::parse($rows[$c->id])->toIso8601String() : null,
            'failed_24h' => (int) ($failed[$c->id] ?? 0),
        ]])->all();
    }

    /** Queues a catalog re-sync. @throws GitConnectionException when the connection is not this tenant's active one */
    public function sync(Tenant $tenant, string $uuid): void
    {
        $connection = GitConnection::query()->where('tenant_id', $tenant->id)->where('uuid', $uuid)
            ->where('status', GitConnection::STATUS_ACTIVE)->first();
        if ($connection === null) {
            throw new GitConnectionException(__('Connection not found.'), 404);
        }

        SyncGitInstallation::dispatch($connection->id);
    }

    /** @throws GitConnectionException when the connection is not this tenant's */
    public function disconnect(Tenant $tenant, string $uuid, ?User $actor = null, ?Request $request = null): void
    {
        $connection = GitConnection::query()->where('tenant_id', $tenant->id)->where('uuid', $uuid)
            ->where('status', '!=', GitConnection::STATUS_REVOKED)->first();
        if ($connection === null) {
            throw new GitConnectionException(__('Connection not found.'), 404);
        }

        // The tokens go with the connection; the row stays as the audit trail of who connected what.
        $connection->forceFill([
            'status' => GitConnection::STATUS_REVOKED, 'revoked_at' => now(), 'access_token' => null, 'refresh_token' => null,
        ])->save();

        $this->audit->record($request, 'git.disconnected', 'git_connection', $connection->id, [
            'tenant_id' => $tenant->id, 'installation_id' => $connection->installation_id, 'account' => $connection->account_login,
            'actor_user_id' => $actor?->id, 'actor_name' => $actor?->name,
        ]);
    }

    private function assertEnabled(): void
    {
        if (! config('git.enabled')) {
            throw new GitConnectionException(__('Git integration is not enabled on this server.'), 404);
        }
    }
}
