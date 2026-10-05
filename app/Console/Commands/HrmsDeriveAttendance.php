<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Hrms\Attendance\WorkLogDerivation;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HRMS — mark attendance days from voluntarily logged work.
 *
 * Opt-in per tenant and off by default: derivation only runs where HR
 * switched `attendance.auto_derive_from_work_logs` on, because work logs
 * are volunteered and a manager could otherwise manufacture attendance.
 * A bare run reports; nothing is written without `--apply` spelled out —
 * the destructive-command rule holds even though derivation overwrites
 * only its own stamped rows.
 */
class HrmsDeriveAttendance extends Command
{
    protected $signature = 'hrms:derive-attendance
        {--date= : Work date YYYY-MM-DD (default today)}
        {--tenant= : Central id of a single tenant}
        {--all : Run for every provisioned tenant}
        {--apply : Write derived day rows (default reports only)}';

    protected $description = 'Derive attendance days from work logs where the tenant opted in';

    public function handle(TenantDatabaseManager $dbm): int
    {
        $scoped = $this->option('tenant') !== null;
        $all = (bool) $this->option('all');

        if ($scoped === $all) {
            $this->error('Pass exactly one of --tenant=ID or --all.');

            return self::FAILURE;
        }

        $date = $this->workDate();

        if ($date === null) {
            return self::FAILURE;
        }

        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->error('No tenant matched. Nothing to do.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        Log::channel('hrms')->info('attendance.derive.command_started', [
            'work_date' => $date->toDateString(),
            'tenants' => $tenants->count(),
            'apply' => $apply,
        ]);

        $rows = [];
        $failed = 0;

        foreach ($tenants as $tenant) {
            $result = $this->sweepTenant($tenant, $dbm, $date, $apply);

            if ($result === null) {
                $failed++;
                $rows[] = [$tenant->slug, '—', '—', 'failed'];

                continue;
            }

            $rows[] = [$tenant->slug, $result['derived'], $result['skipped'], $result['disabled'] ? 'opted out' : ($apply ? 'derived' : 'dry run')];
        }

        $this->table(['Tenant', 'Derived', 'Skipped', 'Mode'], $rows);

        Log::channel('hrms')->info('attendance.derive.command_finished', [
            'work_date' => $date->toDateString(),
            'tenants' => $tenants->count(),
            'failed' => $failed,
        ]);

        if (! $apply) {
            $this->info('Dry run — nothing was written.');
        }

        if ($failed > 0) {
            $this->error("{$failed} tenant(s) failed; see the errors above.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function workDate(): ?Carbon
    {
        $raw = $this->option('date') ?? today()->toDateString();

        try {
            $date = Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        } catch (Throwable) {
            $this->error('--date must be YYYY-MM-DD.');

            return null;
        }

        if ($date->isFuture()) {
            $this->error('--date refuses the future.');

            return null;
        }

        return $date;
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function tenants(): Collection
    {
        if ($this->option('tenant') !== null) {
            $tenant = Tenant::find($this->option('tenant'));

            return collect($tenant === null ? [] : [$tenant]);
        }

        return Tenant::query()->where('status', 'active')->orderBy('id')->get();
    }

    /**
     * @return array{derived: int, skipped: int, disabled: bool}|null
     */
    private function sweepTenant(Tenant $tenant, TenantDatabaseManager $dbm, Carbon $date, bool $apply): ?array
    {
        try {
            return $dbm->using($tenant, fn (): array => app(WorkLogDerivation::class)->derive($date, $apply));
        } catch (Throwable $exception) {
            $this->error("Tenant {$tenant->slug}: {$exception->getMessage()}");

            return null;
        }
    }
}
