<?php

namespace App\Services\Hrms\Analytics;

use App\Models\Hrms\Analytics\ReportSchedule;
use App\Models\User;
use App\Services\Hrms\HrmsAnalyticsService;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Analytics/HRMS — building and sending scheduled digests.
 *
 * Three verbs: resolve the audience, build the redacted report, send it
 * and advance the cursor. Digests carry counts, never names and never
 * pay — the audience is HR-chosen but the content tier is fixed, because
 * a digest that names latecomers to a broad list is a wall of shame with
 * a schedule. Payroll sections are refused outright rather than
 * redacted: a redacted pay total invites its own misreading.
 */
class ReportDigestService
{
    public function __construct(
        private readonly HrmsAnalyticsService $analytics,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Everyone a schedule names: user ids plus role holders, deduplicated.
     * Roles resolve at send time so the digest follows the role as people
     * join and leave it.
     *
     * @return Collection<int, User>
     */
    public function recipients(ReportSchedule $schedule): Collection
    {
        $recipients = $schedule->recipients ?? [];

        $users = User::query()->whereIn('id', array_map('intval', (array) ($recipients['users'] ?? [])))->get();

        foreach ((array) ($recipients['roles'] ?? []) as $slug) {
            $users = $users->concat(User::query()
                ->whereHas('roles', fn ($query) => $query->where('slug', (string) $slug))
                ->get());
        }

        return $users->unique('id')->values();
    }

    /**
     * The digest body: requested sections at redacted tier, payroll
     * refused. Unknown section names are skipped, not failed — a renamed
     * section must degrade a digest, never break the whole run.
     *
     * @return array<string, mixed>
     */
    public function build(ReportSchedule $schedule): array
    {
        $definition = $schedule->definition ?? [];
        $filters = (array) ($definition['filters'] ?? []);
        $sections = [];

        foreach ((array) ($definition['sections'] ?? []) as $section) {
            $sections[$section] = match ($section) {
                'headcount' => $this->analytics->headcount($filters),
                'attendance' => $this->analytics->attendance($filters, false),
                'leave' => $this->analytics->leave($filters, false),
                'lifecycle' => $this->analytics->lifecycle($filters),
                'performance' => $this->analytics->performance($filters),
                'documents' => $this->analytics->documents($filters, false),
                'assets' => $this->analytics->assets($filters),
                default => null,
            };
        }

        return array_filter($sections, fn ($section): bool => $section !== null);
    }

    /**
     * Send one schedule and advance its cursor: recipients hear once, the
     * next run lands one cadence out, and a second sweep today finds
     * nothing due. Delivery is the notification path — no mail transport
     * grows in this phase.
     *
     * @return array{sent: int, skipped: int}
     */
    public function send(ReportSchedule $schedule, ?User $actor = null): array
    {
        return DB::transaction(function () use ($schedule, $actor): array {
            $sections = $this->build($schedule);
            $sent = 0;
            $skipped = 0;

            foreach ($this->recipients($schedule) as $recipient) {
                if ($actor !== null && (int) $recipient->id === (int) $actor->id) {
                    $skipped++;

                    continue;
                }

                $this->notifications->notify($recipient, 'hrms.inbox.digest', [
                    'report_schedule_id' => $schedule->id,
                    'schedule_name' => $schedule->name,
                    'sections' => array_keys($sections),
                ], $actor);
                $sent++;
            }

            $schedule->update([
                'last_run_at' => now(),
                'next_run_at' => $this->advance($schedule, now()),
            ]);

            return ['sent' => $sent, 'skipped' => $skipped];
        });
    }

    private function advance(ReportSchedule $schedule, Carbon $now): Carbon
    {
        return match ($schedule->cadence) {
            'daily' => $now->copy()->addDay(),
            'weekly' => $now->copy()->addWeek(),
            'monthly' => $now->copy()->addMonth(),
            'quarterly' => $now->copy()->addMonths(3),
            default => $now->copy()->addWeek(),
        };
    }
}
