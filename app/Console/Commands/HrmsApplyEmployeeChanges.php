<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\Hrms\Lifecycle\EmployeeChangeService;
use App\Support\Hrms\HrmsSchema;
use Illuminate\Console\Command;

/** HRMS — apply approved transfers / promotions / confirmations whose effective date has arrived. Idempotent. */
class HrmsApplyEmployeeChanges extends Command
{
    use RunsPerTenant;

    protected $signature = 'hrms:apply-employee-changes
        {--tenant= : Central id of a single tenant}
        {--all : Run for every provisioned tenant}';

    protected $description = 'Apply approved employee changes (transfer, promotion, confirmation) that are now due';

    public function handle(EmployeeChangeService $changes): int
    {
        $tenants = $this->tenantsOrFail();

        if ($tenants === null) {
            return self::FAILURE;
        }

        $report = $this->forEachTenant($tenants, fn (): array => HrmsSchema::present() ? [$changes->applyDue(), 'applied'] : ['—', 'no HRMS']);

        $this->table(['Tenant', 'Applied', 'Result'], $report['rows']);

        return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
