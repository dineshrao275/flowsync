<?php

namespace App\Console\Commands;

use App\Enums\Hrms\SurveyCampaignStatus;
use App\Models\Hrms\Survey\SurveyCampaign;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Hrms\Survey\EngagementService;
use App\Services\NotificationService;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HRMS — open and close survey campaigns on schedule.
 *
 * A schedule command and an operator command: nightly it opens what
 * started and closes what ended, then nudges open campaigns ending
 * within three days. The closing nudge is weekly-deduped per campaign
 * (the assets-overdue idiom) — a countdown that pings daily regardless
 * of answers is wallpaper, and answers do not reopen the window.
 */
class HrmsSurveysOpenClose extends Command
{
    protected $signature = 'hrms:surveys-open-close
        {--tenant= : Central id of a single tenant}
        {--all : Run for every provisioned tenant}
        {--dry-run : Report transitions without writing anything}';

    protected $description = 'Open and close survey campaigns on schedule';

    public function handle(TenantDatabaseManager $dbm): int
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

        $dryRun = (bool) $this->option('dry-run');

        Log::channel('hrms')->info('surveys.open_close.command_started', [
            'tenants' => $tenants->count(),
            'dry_run' => $dryRun,
        ]);

        $rows = [];
        $failed = 0;

        foreach ($tenants as $tenant) {
            $result = $this->sweepTenant($tenant, $dbm, $dryRun);

            if ($result === null) {
                $failed++;
                $rows[] = [$tenant->slug, '—', '—', '—', 'failed'];

                continue;
            }

            $rows[] = [$tenant->slug, $result['opened'], $result['closed'], $result['nudged'], $dryRun ? 'dry run' : 'swept'];
        }

        $this->table(['Tenant', 'Opened', 'Closed', 'Nudged', 'Mode'], $rows);

        Log::channel('hrms')->info('surveys.open_close.command_finished', [
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
     * @return array{opened: int, closed: int, nudged: int}|null
     */
    private function sweepTenant(Tenant $tenant, TenantDatabaseManager $dbm, bool $dryRun): ?array
    {
        try {
            return $dbm->using($tenant, function () use ($dryRun): array {
                $service = app(EngagementService::class);
                $now = now();

                $toOpen = SurveyCampaign::query()
                    ->where('status', SurveyCampaignStatus::Scheduled)
                    ->whereNotNull('starts_at')
                    ->where('starts_at', '<=', $now)
                    ->get();

                if ($dryRun) {
                    $toClose = SurveyCampaign::query()
                        ->where('status', SurveyCampaignStatus::Open)
                        ->whereNotNull('ends_at')
                        ->where('ends_at', '<', $now)
                        ->get();

                    return ['opened' => $toOpen->count(), 'closed' => $toClose->count(), 'nudged' => 0];
                }

                $opened = 0;
                $closed = 0;
                $nudged = 0;

                foreach ($toOpen as $campaign) {
                    $service->open($campaign);
                    $opened++;
                }

                // Re-queried after opening: a window already past still
                // closes in the same sweep instead of lingering open until
                // tomorrow's run notices.
                $toClose = SurveyCampaign::query()
                    ->where('status', SurveyCampaignStatus::Open)
                    ->whereNotNull('ends_at')
                    ->where('ends_at', '<', $now)
                    ->get();

                foreach ($toClose as $campaign) {
                    $service->close($campaign);
                    $closed++;
                }

                // Fresh like the closes: a campaign opened above with an
                // end inside three days nudges in the same sweep.
                $closing = SurveyCampaign::query()
                    ->where('status', SurveyCampaignStatus::Open)
                    ->whereNotNull('ends_at')
                    ->where('ends_at', '>=', $now)
                    ->where('ends_at', '<=', $now->copy()->addDays(3))
                    ->get();

                foreach ($closing as $campaign) {
                    $nudged += $this->nudgeClosing($campaign);
                }

                return ['opened' => $opened, 'closed' => $closed, 'nudged' => $nudged];
            });
        } catch (Throwable $exception) {
            $this->error("Tenant {$tenant->slug}: {$exception->getMessage()}");

            return null;
        }
    }

    /**
     * Nudge the audience of a campaign ending within three days — once per
     * campaign per week, skipped entirely for invitees who already
     * answered (a nudge to someone done is nagging, not reminding).
     */
    private function nudgeClosing(SurveyCampaign $campaign): int
    {
        $service = app(EngagementService::class);
        $notifications = app(NotificationService::class);
        $nudged = 0;

        foreach ($service->audience($campaign) as $employee) {
            if ($employee->user_id === null) {
                continue;
            }

            $recipient = User::find((int) $employee->user_id);

            if ($recipient === null) {
                continue;
            }

            // Anonymous answers carry no employee link, so this check
            // cannot see them: an anonymous respondent may be nudged once
            // more, which the copy ("if you already answered, ignore it")
            // must say out loud on the client.
            $answered = $campaign->responses()->where('employee_id', $employee->id)->exists();

            if ($answered) {
                continue;
            }

            $recentlyNudged = UserNotification::query()
                ->where('type', 'hrms.survey.closing_soon')
                ->where('data->survey_campaign_id', $campaign->id)
                ->where('user_id', $recipient->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->exists();

            if ($recentlyNudged) {
                continue;
            }

            $notifications->notify($recipient, 'hrms.survey.closing_soon', [
                'survey_campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
                'ends_at' => $campaign->ends_at?->toIso8601String(),
            ]);
            $nudged++;
        }

        return $nudged;
    }
}
