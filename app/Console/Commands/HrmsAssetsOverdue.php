<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\UserNotification;
use App\Services\Hrms\Asset\AssetAssignmentService;
use App\Services\NotificationService;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HRMS — nudge unacknowledged asset handovers.
 *
 * A schedule command and an operator command: nightly it reminds holders
 * (and the manage pool) about signatures past the threshold, on demand it
 * re-pings a tenant. At most one nudge per handover per week — without
 * this the command re-pings the same silent pile every morning until
 * someone acts, and a notification that arrives daily regardless of action
 * is wallpaper.
 */
class HrmsAssetsOverdue extends Command
{
    protected $signature = 'hrms:assets-overdue
        {--days=14 : Handovers unacknowledged this long count as overdue}
        {--tenant= : Central id of a single tenant}
        {--all : Run for every provisioned tenant}
        {--dry-run : Report overdue handovers without writing anything}';

    protected $description = 'Notify holders of unacknowledged asset handovers';

    public function handle(NotificationService $notifications, TenantDatabaseManager $dbm): int
    {
        $scoped = $this->option('tenant') !== null;
        $all = (bool) $this->option('all');

        if ($scoped === $all) {
            $this->error('Pass exactly one of --tenant=ID or --all.');

            return self::FAILURE;
        }

        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->error('No tenant matched. Nothing to do.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');

        Log::channel('hrms')->info('assets.overdue.command_started', [
            'days' => $days,
            'tenants' => $tenants->count(),
            'dry_run' => $dryRun,
        ]);

        $rows = [];
        $failed = 0;

        foreach ($tenants as $tenant) {
            $result = $this->nudgeTenant($tenant, $notifications, $dbm, $days, $dryRun);

            if ($result === null) {
                $failed++;
                $rows[] = [$tenant->slug, '—', '—', 'failed'];

                continue;
            }

            $rows[] = [$tenant->slug, $result['due'], $result['sent'], $dryRun ? 'dry run' : 'nudged'];
        }

        $this->table(['Tenant', 'Overdue', 'Sent', 'Mode'], $rows);

        Log::channel('hrms')->info('assets.overdue.command_finished', [
            'days' => $days,
            'tenants' => $tenants->count(),
            'failed' => $failed,
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
     * @return array{due: int, sent: int}|null
     */
    private function nudgeTenant(
        Tenant $tenant,
        NotificationService $notifications,
        TenantDatabaseManager $dbm,
        int $days,
        bool $dryRun,
    ): ?array {
        try {
            return $dbm->using($tenant, function () use ($days, $dryRun, $notifications): array {
                $due = app(AssetAssignmentService::class)->overdueReturns($days);

                if ($dryRun) {
                    return ['due' => $due->count(), 'sent' => 0];
                }

                $sent = 0;

                foreach ($due as $assignment) {
                    $recentlyNudged = UserNotification::query()
                        ->where('type', 'hrms.asset.return_overdue')
                        ->where('data->asset_assignment_id', $assignment->id)
                        ->where('created_at', '>=', now()->subDays(7))
                        ->exists();

                    if ($recentlyNudged) {
                        continue;
                    }

                    $sent += count($notifications->assetReturnOverdue($assignment));
                }

                return ['due' => $due->count(), 'sent' => $sent];
            });
        } catch (Throwable $exception) {
            $this->error("Tenant {$tenant->slug}: {$exception->getMessage()}");

            return null;
        }
    }
}
