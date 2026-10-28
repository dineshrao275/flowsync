<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tasks\SlaService;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class TasksSlaSweep extends Command
{
    protected $signature = 'tasks:sla-sweep {--all : every serviceable tenant}';

    protected $description = 'Record and announce task SLA breaches';

    public function handle(TenantDatabaseManager $dbm): int
    {
        $total = 0;
        $tenants = Tenant::query()->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->get();

        foreach ($tenants as $tenant) {
            $dbm->using($tenant, function () use (&$total): void {
                if (Schema::hasTable('sla_policies')) {
                    $total += app(SlaService::class)->sweep();
                }
            });
        }

        $this->info("Recorded {$total} SLA breach(es) across {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
