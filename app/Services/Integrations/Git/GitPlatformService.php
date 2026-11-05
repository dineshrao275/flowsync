<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use App\Jobs\SyncGitInstallation;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\GitWebhookDelivery;
use App\Models\User;
use App\Services\PlatformAudit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The platform operator's view of Git: are connections healthy, are deliveries arriving, can I pause one.
 * It is deliberately blind to tenant content - repository names, branches, PR text and commit messages never
 * appear here (payload excerpts are not returned), only counts, states and timestamps.
 */
class GitPlatformService
{
    /** A connection with no catalog sync for this long is called stale. */
    public const STALE_AFTER_DAYS = 3;

    public function __construct(private readonly PlatformAudit $audit) {}

    public function connections(?string $status, ?string $term): LengthAwarePaginator
    {
        $page = GitConnection::query()->with('tenant:id,name,slug')
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($term, fn ($q, $t) => $q->where(fn ($w) => $w->where('account_login', 'like', '%'.addcslashes($t, '%_').'%')
                ->orWhereHas('tenant', fn ($tq) => $tq->where('name', 'like', '%'.addcslashes($t, '%_').'%')->orWhere('slug', 'like', '%'.addcslashes($t, '%_').'%'))))
            ->withCount(['repositories as repositories_count' => fn ($q) => $q->where('available', true)])
            ->orderByDesc('id')->paginate(25);

        $ids = $page->getCollection()->pluck('id')->all();
        $last = GitWebhookDelivery::query()->whereIn('git_connection_id', $ids)->selectRaw('git_connection_id, max(received_at) as at')->groupBy('git_connection_id')->pluck('at', 'git_connection_id');
        $failed = GitWebhookDelivery::query()->whereIn('git_connection_id', $ids)->where('status', GitWebhookDelivery::STATUS_FAILED)
            ->where('received_at', '>=', now()->subDay())->selectRaw('git_connection_id, count(*) as n')->groupBy('git_connection_id')->pluck('n', 'git_connection_id');

        return $page->through(fn (GitConnection $c) => [
            'uuid' => $c->uuid,
            'tenant' => ['id' => $c->tenant_id, 'name' => $c->tenant?->name, 'slug' => $c->tenant?->slug],
            'account_login' => $c->account_login,
            'installation_id' => $c->installation_id,
            'status' => $c->status,
            'disabled_by_platform' => (bool) (((array) $c->settings)['disabled_by_platform'] ?? false),
            'repositories_count' => (int) $c->repositories_count,
            'last_synced_at' => $c->last_synced_at?->toIso8601String(),
            'stale' => $c->isActive() && ($c->last_synced_at === null || $c->last_synced_at->lt(now()->subDays(self::STALE_AFTER_DAYS))),
            'last_delivery_at' => isset($last[$c->id]) ? Carbon::parse($last[$c->id])->toIso8601String() : null,
            'failed_deliveries_24h' => (int) ($failed[$c->id] ?? 0),
            'connected_at' => $c->created_at?->toIso8601String(),
        ]);
    }

    public function deliveries(?string $status, ?string $connectionUuid): LengthAwarePaginator
    {
        $connectionId = $connectionUuid ? GitConnection::query()->where('uuid', $connectionUuid)->value('id') : null;

        return GitWebhookDelivery::query()
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($connectionUuid, fn ($q) => $q->where('git_connection_id', $connectionId ?? 0))
            ->orderByDesc('id')->paginate(50)
            ->through(fn (GitWebhookDelivery $d) => [
                'id' => $d->id, 'delivery_uuid' => $d->delivery_uuid, 'tenant_id' => $d->tenant_id, 'event' => $d->event, 'action' => $d->action,
                'status' => $d->status, 'error' => $d->error, 'payload_bytes' => $d->payload_bytes,
                'received_at' => $d->received_at?->toIso8601String(), 'processed_at' => $d->processed_at?->toIso8601String(),
            ]);
    }

    public function sync(string $uuid, ?User $actor, ?Request $request): void
    {
        $connection = $this->find($uuid);
        if (! $connection->isActive()) {
            throw new GitConnectionException(__('Only an active connection can be synced.'), 422);
        }
        SyncGitInstallation::dispatch($connection->id);
        $this->audit->record($request, 'git.platform_sync', 'git_connection', $connection->id, $this->context($connection, $actor));
    }

    public function disable(string $uuid, ?User $actor, ?Request $request): void
    {
        $connection = $this->find($uuid);
        $connection->forceFill([
            'status' => GitConnection::STATUS_SUSPENDED, 'suspended_at' => now(),
            'settings' => ((array) $connection->settings) + ['disabled_by_platform' => true],
        ])->save();
        Cache::forget("git:token:{$connection->installation_id}");
        $this->audit->record($request, 'git.platform_disabled', 'git_connection', $connection->id, $this->context($connection, $actor));
    }

    public function enable(string $uuid, ?User $actor, ?Request $request): void
    {
        $connection = $this->find($uuid);
        $settings = (array) $connection->settings;
        if (empty($settings['disabled_by_platform'])) {
            throw new GitConnectionException(__('This connection was not disabled by the platform.'), 422);
        }
        unset($settings['disabled_by_platform']);
        $connection->forceFill(['status' => GitConnection::STATUS_ACTIVE, 'suspended_at' => null, 'settings' => $settings === [] ? null : $settings])->save();
        SyncGitInstallation::dispatch($connection->id);
        $this->audit->record($request, 'git.platform_enabled', 'git_connection', $connection->id, $this->context($connection, $actor));
    }

    /** Totals for the platform health page and the metrics endpoint. @return array<string, mixed> */
    public function health(): array
    {
        $byStatus = GitConnection::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $last = GitWebhookDelivery::query()->max('received_at');

        return [
            'enabled' => (bool) config('git.enabled'),
            'connections' => $byStatus,
            'repositories_available' => GitRepository::query()->where('available', true)->count(),
            'last_delivery_at' => $last ? Carbon::parse($last)->toIso8601String() : null,
            'failed_deliveries_24h' => GitWebhookDelivery::query()->where('status', GitWebhookDelivery::STATUS_FAILED)->where('received_at', '>=', now()->subDay())->count(),
            'stale_connections' => GitConnection::query()->where('status', GitConnection::STATUS_ACTIVE)
                ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subDays(self::STALE_AFTER_DAYS)))->count(),
        ];
    }

    private function find(string $uuid): GitConnection
    {
        return GitConnection::query()->where('uuid', $uuid)->where('status', '!=', GitConnection::STATUS_REVOKED)->first()
            ?? throw new GitConnectionException(__('Connection not found.'), 404);
    }

    /** @return array<string, mixed> */
    private function context(GitConnection $connection, ?User $actor): array
    {
        return ['tenant_id' => $connection->tenant_id, 'account' => $connection->account_login, 'actor_id' => $actor?->id, 'actor_name' => $actor?->name];
    }
}
