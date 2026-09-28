<?php

namespace App\Console\Commands;

use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Tenant;
use App\Models\UserNotification;
use App\Services\NotificationService;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HRMS — nudge the owners of onboarding items coming due.
 *
 * A daily schedule command, not a migration: it reads open items due within
 * the window and notifies through the same `onboardingTaskDue` the case
 * creation uses, so the two paths cannot disagree about who hears what. At
 * most one nudge per item per week — a daily repeat of the same overdue item
 * trains people to ignore the one notification this feature owns.
 */
class HrmsOnboardingReminders extends Command
{
    protected $signature = 'hrms:onboarding-reminders
        {--days=3 : Notify items due within this many days}
        {--tenant= : Central id of a single tenant}
        {--all : Run for every tenant that has a database}
        {--dry-run : Report what would be notified without writing anything}';

    protected $description = 'Notify the owners of onboarding checklist items coming due';

    public function handle(NotificationService $notifications, TenantDatabaseManager $dbm): int
    {
        $scoped = $this->option('tenant') !== null;
        $all = (bool) $this->option('all');

        // Neither is a footgun and both is a contradiction, so both are
        // refused rather than one silently winning (see hrms:backfill-employees).
        if ($scoped === $all) {
            $this->error('Pass exactly one of --tenant=ID or --all.');

            return self::FAILURE;
        }

        $days = max(0, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->error('No tenant matched. Nothing to do.');

            return self::FAILURE;
        }

        $this->info(($dryRun ? 'Planning' : 'Reminding')." for items due within {$days} day(s), across {$tenants->count()} tenant(s).");

        $rows = [];
        $failed = 0;

        foreach ($tenants as $tenant) {
            $result = $this->remindTenant($tenant, $notifications, $dbm, $days, $dryRun);

            if ($result === null) {
                $failed++;
                $rows[] = [$tenant->slug, '—', '—', 'failed'];

                continue;
            }

            $rows[] = [$tenant->slug, $result['due'], $result['sent'], $dryRun ? 'dry run' : 'sent'];
        }

        $this->table(['Tenant', 'Due items', 'Notifications', 'Mode'], $rows);

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

            return $tenant ? collect([$tenant]) : collect();
        }

        return Tenant::query()
            ->whereNotNull('provisioned_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{due: int, sent: int}|null Null when the tenant could not be processed.
     */
    private function remindTenant(
        Tenant $tenant,
        NotificationService $notifications,
        TenantDatabaseManager $dbm,
        int $days,
        bool $dryRun,
    ): ?array {
        try {
            return $dbm->using($tenant, function () use ($notifications, $days, $dryRun): array {
                $due = OnboardingCaseTask::query()
                    ->whereHas('case', fn ($query) => $query->open())
                    ->open()
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<=', today()->addDays($days)->toDateString())
                    ->with(['case.employee', 'owner'])
                    ->orderBy('due_date')
                    ->orderBy('id')
                    ->get();

                if ($dryRun) {
                    return ['due' => $due->count(), 'sent' => 0];
                }

                $sent = 0;

                foreach ($due as $task) {
                    // At most one nudge per item per week: without this the
                    // command re-pings the same overdue pile every morning
                    // until someone acts, and a notification that arrives
                    // daily regardless of action is wallpaper.
                    $recentlyNudged = UserNotification::query()
                        ->where('type', 'hrms.onboarding.task_due')
                        ->where('data->case_task_id', $task->id)
                        ->where('created_at', '>=', now()->subDays(7))
                        ->exists();

                    if ($recentlyNudged) {
                        continue;
                    }

                    $sent += count($notifications->onboardingTaskDue($task->case->employee, $task));
                }

                return ['due' => $due->count(), 'sent' => $sent];
            });
        } catch (Throwable $exception) {
            $this->error("Tenant {$tenant->slug} (#{$tenant->id}): ".$exception->getMessage());

            return null;
        }
    }
}
