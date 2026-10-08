<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\UsageMetric;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CollectTenantUsage extends Command
{
    protected $signature = 'tenants:collect-usage {--tenant= : Collect for a specific tenant ID}';

    protected $description = 'Collect and aggregate usage metrics for tenant databases';

    public function handle(TenantDatabaseManager $dbm): int
    {
        $tenantId = $this->option('tenant');
        $query = Tenant::query();

        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        $tenants = $query->get()->filter(fn (Tenant $t) => $t->isServiceable());
        $today = Carbon::today()->toDateString();
        $collectedCount = 0;

        foreach ($tenants as $tenant) {
            $metrics = $dbm->using($tenant, function () {
                $storageBytes = (int) Attachment::sum('size');
                if (class_exists(EmployeeDocument::class)) {
                    $storageBytes += (int) EmployeeDocument::sum('size');
                }

                return [
                    'users' => User::count(),
                    'workspaces' => Workspace::count(),
                    'projects' => Project::count(),
                    'tasks' => Task::count(),
                    'storage_bytes' => $storageBytes,
                ];
            });

            foreach ($metrics as $key => $value) {
                UsageMetric::updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'metric_key' => $key,
                        'period' => $today,
                    ],
                    [
                        'value' => $value,
                    ]
                );
            }

            $collectedCount++;
            $this->line("Collected metrics for tenant: {$tenant->slug} (ID: {$tenant->id})");
        }

        $this->info("Successfully collected usage metrics for {$collectedCount} tenant(s).");

        return self::SUCCESS;
    }
}
