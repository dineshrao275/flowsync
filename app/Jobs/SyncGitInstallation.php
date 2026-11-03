<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\GitConnection;
use App\Services\Integrations\Git\GitProviderException;
use App\Services\Integrations\Git\GitRepositorySync;
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
            $sync->sync($connection);
        } catch (GitProviderException $e) {
            Log::warning('git.sync_failed', ['connection' => $connection->uuid, 'status' => $e->status]);
        }
    }
}
