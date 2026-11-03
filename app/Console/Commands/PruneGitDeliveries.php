<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GitEventLink;
use App\Models\GitWebhookDelivery;
use App\Models\Tenant;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook delivery rows (replay guard + health log) and the per-tenant event ledger are kept for `git.retention_days`.
 * Task-side pull requests, commits and branches are tenant data and are never pruned here.
 */
class PruneGitDeliveries extends Command
{
    protected $signature = 'git:prune-deliveries {--days= : Override git.retention_days}';

    protected $description = 'Delete old Git webhook delivery records and event-ledger rows';

    public function handle(TenantDatabaseManager $databases): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('git.retention_days')));
        $cutoff = now()->subDays($days);
        $deliveries = GitWebhookDelivery::query()->where('received_at', '<', $cutoff)->delete();

        $links = 0;
        foreach (Tenant::query()->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->get() as $tenant) {
            $links += $databases->using($tenant, fn () => Schema::hasTable('git_event_links')
                ? GitEventLink::query()->where('created_at', '<', $cutoff)->delete() : 0);
        }

        $this->info("Pruned {$deliveries} Git webhook deliveries and {$links} event-ledger rows.");

        return self::SUCCESS;
    }
}
