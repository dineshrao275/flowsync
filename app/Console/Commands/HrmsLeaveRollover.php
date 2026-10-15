<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\Hrms\Leave\LeaveCalendar;
use App\Services\Hrms\Leave\LeaveYearRollover;
use App\Support\Hrms\HrmsSchema;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * HRMS — carry forward / lapse leave balances at the close of a leave year.
 *
 * A schedule command (daily: it only acts once a tenant's leave year has
 * ended, because each tenant's year boundary is its own) and an operator
 * command. Idempotent per employee and type, so re-running repairs a crashed
 * pass. Without `--year` each tenant rolls the leave year that just closed.
 */
class HrmsLeaveRollover extends Command
{
    use RunsPerTenant;

    protected $signature = 'hrms:leave-rollover
        {--year= : The closed leave year to roll over (default: the year before each tenant\'s current one)}
        {--tenant= : Central id of a single tenant}
        {--all : Run for every provisioned tenant}
        {--dry-run : Report carried/lapsed days without writing anything}';

    protected $description = 'Carry forward or lapse leave balances for a closed leave year';

    public function handle(LeaveYearRollover $rollover, LeaveCalendar $calendar): int
    {
        $tenants = $this->tenantsOrFail();

        if ($tenants === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $explicit = $this->option('year') !== null ? (int) $this->option('year') : null;

        $report = $this->forEachTenant($tenants, function () use ($rollover, $calendar, $explicit, $dryRun): array {
            if (! HrmsSchema::present()) {
                return ['—', '—', '—', 'no HRMS'];
            }

            $year = $explicit ?? $calendar->leaveYearFor(now()) - 1;

            try {
                $result = $rollover->rollover($year, null, $dryRun);
            } catch (ValidationException) {
                // The year is not closed in this tenant yet: nothing to do, not a failure.
                return [$year, '—', '—', 'not closed'];
            }

            return [$year, $result['carried'], $result['lapsed'], $dryRun ? 'dry run' : 'rolled'];
        });

        $this->table(['Tenant', 'Year', 'Carried', 'Lapsed', 'Mode'], $report['rows']);

        return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
