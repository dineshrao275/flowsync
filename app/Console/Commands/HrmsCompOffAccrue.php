<?php

namespace App\Console\Commands;

use App\Models\Hrms\Employee\Employee;
use App\Models\Tenant;
use App\Services\Hrms\CompOff\CompOffCredits;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HRMS — bank rest days as comp-off for one tenant or all of them.
 *
 * A schedule command (monthly, over the previous month) and an operator
 * command: the calendar accrual is idempotent per (employee, date, source),
 * so a rerun credits nothing new and repairing a missed month is just
 * running it with --from/--to. Dry runs report headcounts without writing.
 */
class HrmsCompOffAccrue extends Command
{
    protected $signature = 'hrms:comp-off-accrue
        {--from= : First date YYYY-MM-DD (default first day of the previous month)}
        {--to= : Last date YYYY-MM-DD (default last day of the previous month)}
        {--tenant= : Central id of a single tenant}
        {--all : Run for every provisioned tenant}
        {--dry-run : Report qualifying rest days without writing anything}';

    protected $description = 'Credit comp-off for rostered rest days worked past or banked';

    public function handle(CompOffCredits $credits, TenantDatabaseManager $dbm): int
    {
        $scoped = $this->option('tenant') !== null;
        $all = (bool) $this->option('all');

        // Exactly one scope, as in the sibling HRMS commands: neither is a
        // footgun and both is a contradiction, so both are refused rather
        // than one silently winning.
        if ($scoped === $all) {
            $this->error('Pass exactly one of --tenant=ID or --all.');

            return self::FAILURE;
        }

        [$from, $to] = $this->window();

        if ($from === null || $to === null) {
            return self::FAILURE;
        }

        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->error('No tenant matched. Nothing to do.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $started = microtime(true);

        Log::channel('hrms')->info('comp_off.accrue.command_started', [
            'from' => $from,
            'to' => $to,
            'tenants' => $tenants->count(),
            'dry_run' => $dryRun,
        ]);

        $rows = [];
        $failed = 0;
        $credited = 0;

        foreach ($tenants as $tenant) {
            $result = $this->accrueTenant($tenant, $credits, $dbm, $from, $to, $dryRun);

            if ($result === null) {
                $failed++;
                $rows[] = [$tenant->slug, '—', 'failed'];

                continue;
            }

            $credited += $dryRun ? 0 : $result['credited'];
            $rows[] = [$tenant->slug, $result['credited'], $dryRun ? 'dry run' : 'credited'];
        }

        $this->table(['Tenant', 'Credits', 'Mode'], $rows);

        Log::channel('hrms')->info('comp_off.accrue.command_finished', [
            'from' => $from,
            'to' => $to,
            'tenants' => $tenants->count(),
            'failed' => $failed,
            'credited' => $credited,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        if ($dryRun) {
            $this->info('Dry run — nothing was written.');
        }

        if ($failed > 0) {
            $this->error("{$failed} tenant(s) failed; see the errors above.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The window to close: explicit bounds, or the previous calendar month —
     * the scheduled run banks what just happened, never what is happening.
     *
     * @return array{string|null, string|null}
     */
    private function window(): array
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if (($from === null) !== ($to === null)) {
            $this->error('Pass both --from and --to, or neither for the previous month.');

            return [null, null];
        }

        if ($from === null) {
            $start = today()->startOfMonth()->subMonth();

            return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
        }

        foreach (['from' => $from, 'to' => $to] as $name => $raw) {
            try {
                $parsed = Carbon::createFromFormat('Y-m-d', trim((string) $raw));
            } catch (Throwable) {
                $parsed = false;
            }

            if ($parsed === false || $parsed->format('Y-m-d') !== trim((string) $raw)) {
                $this->error('Pass --from/--to as YYYY-MM-DD.');

                return [null, null];
            }
        }

        if ($from > $to) {
            $this->error('The window ends before it starts.');

            return [null, null];
        }

        if ($to > today()->toDateString()) {
            $this->error('The accrual cannot bank days that have not happened yet.');

            return [null, null];
        }

        return [$from, $to];
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function tenants(): Collection
    {
        if ($this->option('tenant') !== null) {
            $tenant = Tenant::find($this->option('tenant'));

            return $tenant ? collect([$tenant]) : collect();
        }

        return Tenant::query()
            ->whereNotNull('provisioned_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{credited: int, skipped: int}|null Null when the tenant failed.
     */
    private function accrueTenant(
        Tenant $tenant,
        CompOffCredits $credits,
        TenantDatabaseManager $dbm,
        string $from,
        string $to,
        bool $dryRun,
    ): ?array {
        try {
            return $dbm->using($tenant, function () use ($credits, $from, $to, $dryRun): array {
                $credited = 0;
                $skipped = 0;

                foreach (Employee::query()->active()->orderBy('id')->get() as $employee) {
                    $result = $credits->creditFromCalendar($employee, $from, $to, null, $dryRun);
                    $credited += $result['credited'];
                    $skipped += $result['skipped'];
                }

                return ['credited' => $credited, 'skipped' => $skipped];
            });
        } catch (Throwable $exception) {
            $this->error("Tenant {$tenant->slug} (#{$tenant->id}): ".$exception->getMessage());

            return null;
        }
    }
}
