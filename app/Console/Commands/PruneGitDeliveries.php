<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GitWebhookDelivery;
use Illuminate\Console\Command;

/** Webhook delivery rows (replay guard + health log) are kept for `git.retention_days`. */
class PruneGitDeliveries extends Command
{
    protected $signature = 'git:prune-deliveries {--days= : Override git.retention_days}';

    protected $description = 'Delete old Git webhook delivery records';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('git.retention_days')));
        $count = GitWebhookDelivery::query()->where('received_at', '<', now()->subDays($days))->delete();

        $this->info("Pruned {$count} Git webhook deliveries.");

        return self::SUCCESS;
    }
}
