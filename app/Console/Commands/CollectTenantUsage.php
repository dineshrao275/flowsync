<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\UsageMetric;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Hrms\HrmsSchema;
use App\Support\TenantDatabaseManager;
use App\Support\Tms\TmsSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
                $storageBytes = TmsSchema::present() ? (int) Attachment::sum('size') : 0;
                if (HrmsSchema::present() && class_exists(EmployeeDocument::class)) {
                    $storageBytes += (int) EmployeeDocument::sum('size');
                }

                // F11.10: who actually used the product recently, from the tenant's own activity ledger.
                $active = fn (int $days) => (int) DB::table('activities')->whereNotNull('actor_user_id')->where('created_at', '>=', now()->subDays($days))->distinct()->count('actor_user_id');

                return [
                    'active_users_7d' => $active(7),
                    'active_users_30d' => $active(30),
                    'activity_30d' => (int) DB::table('activities')->where('created_at', '>=', now()->subDays(30))->count(),
                    'users' => User::count(),
                    'workspaces' => TmsSchema::present() ? Workspace::count() : 0,
                    'projects' => TmsSchema::present() ? Project::count() : 0,
                    'tasks' => TmsSchema::present() ? Task::count() : 0,
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
