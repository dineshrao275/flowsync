<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * G-1: the fleet sweeps exist only if routes/console.php registers them —
 * before this wiring only usage collection and backups were scheduled and
 * every HRMS `--all` command was dead code no cron ever reached.
 */
class ScheduleRegistrationTest extends TestCase
{
    /**
     * @return array<int, string> every scheduled command's artisan string
     */
    private function scheduledCommands(): array
    {
        // routes/console.php is a command route path: requiring it is what
        // registers the Schedule events (ApplicationBuilder::withCommands →
        // Kernel::discoverCommands), and the console kernel is not otherwise
        // bootstrapped by an HTTP-bound test.
        $kernel = $this->app->make(Kernel::class);
        $kernel->bootstrap();

        return collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event) => $event->command !== null)
            ->map(fn (Event $event) => (string) $event->command)
            ->values()
            ->all();
    }

    public function test_the_schedule_registers_every_fleet_sweep(): void
    {
        $scheduled = $this->scheduledCommands();
        $joined = implode("\n", $scheduled);

        $expected = [
            'tenants:collect-usage',
            'tenants:backup --all --verify',
            'tenants:prune-exports',
            'tenants:prune-drafts',
            'events:prune --all',
            'automation:time-triggers --all',
            'tenants:close-impersonations',
            'tenants:expire-trials',
            'billing:period-ends',
            'billing:dunning',
            'hrms:surveys-open-close --all',
            'hrms:documents-expiry --all',
            'hrms:comp-off-accrue --all',
            'hrms:retention --all',
            'hrms:onboarding-reminders --all',
            'hrms:assets-overdue --all',
            'hrms:report-digests --all',
            'hrms:performance-evidence --all',
            'hrms:attendance-rollup --all',
            'hrms:derive-attendance --all --apply',
        ];

        foreach ($expected as $needle) {
            $this->assertStringContainsString($needle, $joined, "Not scheduled: {$needle}");
        }
    }

    public function test_every_hrms_fleet_sweep_is_scoped_to_all_tenants(): void
    {
        $hrms = array_values(array_filter(
            $this->scheduledCommands(),
            fn (string $command) => str_contains($command, 'hrms:'),
        ));

        $this->assertNotEmpty($hrms, 'No hrms:* commands are scheduled at all.');

        foreach ($hrms as $command) {
            $this->assertStringContainsString('--all', $command, "Fleet sweep not scoped --all: {$command}");
        }
    }

    public function test_operator_only_commands_stay_off_the_schedule(): void
    {
        $joined = implode("\n", $this->scheduledCommands());

        // backfill = data migration, statutory-recompute names a specific run:
        // both require a human decision, so a cron must never pick them up.
        $this->assertStringNotContainsString('hrms:backfill-employees', $joined);
        $this->assertStringNotContainsString('hrms:statutory-recompute', $joined);
        // Retention's scheduled pass reports only — deleting is --apply, manual.
        $this->assertStringNotContainsString('hrms:retention --all --apply', $joined);
    }
}
