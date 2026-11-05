<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncGitInstallation;
use App\Models\GitConnection;
use Illuminate\Console\Command;

/** Safety-net re-sync of every active connection's repository catalog (webhooks keep it current in between). */
class SyncGitInstallations extends Command
{
    protected $signature = 'git:sync-installations {--all : every active connection} {--tenant= : one tenant id}';

    protected $description = 'Queue a repository catalog sync for connected Git installations';

    public function handle(): int
    {
        if (! config('git.enabled')) {
            $this->info('Git integration is disabled; nothing to sync.');

            return self::SUCCESS;
        }
        if (! $this->option('all') && ! $this->option('tenant')) {
            $this->error('Pass --all or --tenant=ID.');

            return self::FAILURE;
        }

        $count = 0;
        GitConnection::query()->where('status', GitConnection::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('tenant_id', (int) $id))
            // Staggered so a large fleet does not hit the provider in one burst.
            ->each(function (GitConnection $connection) use (&$count): void {
                SyncGitInstallation::dispatch($connection->id)->delay(now()->addSeconds($count * 2));
                $count++;
            });

        $this->info("Queued {$count} Git sync(s).");

        return self::SUCCESS;
    }
}
