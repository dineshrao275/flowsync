<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\Hrms\Leave\LeaveConsistency;
use App\Support\Hrms\HrmsSchema;
use Illuminate\Console\Command;

/**
 * HRMS — reports (and with --repair fixes) leave balances that no longer match
 * their ledger. Read-only unless --repair is given; exit code 1 when drift is
 * found and left unrepaired, so it can gate a deploy or a monitor.
 */
class HrmsLeaveConsistency extends Command
{
    use RunsPerTenant;

    protected $signature = 'hrms:leave-consistency
        {--tenant= : Central id of a single tenant}
        {--all : Run for every provisioned tenant}
        {--repair : Rebuild drifted balances from the ledger}';

    protected $description = 'Find leave balances that disagree with their ledger (and optionally repair them)';

    public function handle(LeaveConsistency $consistency): int
    {
        $tenants = $this->tenantsOrFail();

        if ($tenants === null) {
            return self::FAILURE;
        }

        $repair = (bool) $this->option('repair');
        $unrepaired = 0;
        $details = [];

        $report = $this->forEachTenant($tenants, function ($tenant) use ($consistency, $repair, &$unrepaired, &$details): array {
            if (! HrmsSchema::present()) {
                return ['—', '—', 'no HRMS'];
            }

            $drift = $consistency->scan();

            foreach ($drift as $row) {
                $fields = collect($row['fields'])
                    ->map(fn ($v, $k) => "$k {$v['stored']}→{$v['expected']}")->implode(', ');
                $details[] = "{$tenant->slug}: employee {$row['employee_id']} type {$row['leave_type_id']} year {$row['year']} ($fields)";
            }

            if ($repair && $drift !== []) {
                return [count($drift), $consistency->repair($drift), 'repaired'];
            }

            $unrepaired += count($drift);

            return [count($drift), 0, $drift === [] ? 'consistent' : 'DRIFT'];
        });

        $this->table(['Tenant', 'Drifted', 'Repaired', 'Result'], $report['rows']);

        foreach ($details as $line) {
            $this->line($line);
        }

        return ($report['failed'] > 0 || $unrepaired > 0) ? self::FAILURE : self::SUCCESS;
    }
}
