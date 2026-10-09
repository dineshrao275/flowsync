<?php

namespace App\Console\Commands;

use App\Models\DomainEvent;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** Removes processed domain events and settled webhook deliveries past their retention. */
class PruneDomainEvents extends Command
{
    protected $signature = 'events:prune {--all : every serviceable tenant}';

    protected $description = 'Delete old processed domain events and finished webhook deliveries';

    public function handle(TenantDatabaseManager $dbm): int
    {
        $events = 0;
        $deliveries = 0;
        $tenants = Tenant::query()->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->get();

        foreach ($tenants as $tenant) {
            $dbm->using($tenant, function () use (&$events, &$deliveries): void {
                if (Schema::hasTable('domain_events')) {
                    $events += DomainEvent::whereNotNull('processed_at')->where('processed_at', '<', now()->subDays((int) config('domain_events.retention_days', 30)))->delete();
                }
                if (Schema::hasTable('webhook_deliveries')) {
                    $deliveries += WebhookDelivery::whereIn('status', ['delivered', 'failed'])->where('created_at', '<', now()->subDays((int) config('webhooks.retention_days', 30)))->delete();
                }
            });
        }

        $this->info("Pruned {$events} event(s) and {$deliveries} delivery record(s) across {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
