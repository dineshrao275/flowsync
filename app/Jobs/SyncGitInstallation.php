<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\GitConnection;
use App\Services\Integrations\Git\GitProviderException;
use App\Services\Integrations\Git\GitRepositorySync;
use App\Services\PlatformAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/** Refreshes one connection's repository catalog. Never throws: a failure is logged and the next sync retries. */
class SyncGitInstallation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $connectionId) {}

    public function handle(GitRepositorySync $sync): void
    {
        $connection = GitConnection::query()->find($this->connectionId);
        if ($connection === null || ! $connection->isActive()) {
            return;
        }

        try {
            $count = $sync->sync($connection);
            $this->remember($connection, null);
            app(PlatformAudit::class)->record(null, 'git.synced', 'git_connection', $connection->id, ['tenant_id' => $connection->tenant_id, 'repositories' => $count]);
        } catch (GitProviderException $e) {
            Log::warning('git.sync_failed', ['connection' => $connection->uuid, 'status' => $e->status]);
            $this->remember($connection, $e->isRateLimited() ? __('GitHub is rate limiting requests; the next sync will retry.') : $e->getMessage());
        }
    }

    /** The last failure is shown to the tenant admin until a sync succeeds. */
    private function remember(GitConnection $connection, ?string $error): void
    {
        $settings = (array) $connection->settings;
        if ($error === null) {
            unset($settings['sync_error']);
        } else {
            $settings['sync_error'] = $error;
        }
        $connection->forceFill(['settings' => $settings === [] ? null : $settings])->save();
    }
}
