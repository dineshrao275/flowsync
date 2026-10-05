<?php

namespace Tests\Feature;

use App\Models\Hrms\Analytics\ReportSchedule;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Hrms\Analytics\ReportDigestService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P18.4 — digests send once and advance.
 *
 * Due schedules notify their audience (user ids plus role holders) with
 * counts, never names and never pay; unknown sections degrade the build
 * instead of breaking the run; and the cursor advances, so a second sweep
 * finds nothing due and sends nothing new.
 */
class HrmsReportDigestTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_due_digest_notifies_and_advances(): void
    {
        $anna = $this->makeUser('Digest Anna');
        $bob = $this->makeUser('Digest Bob');

        $schedule = $this->schedule(['users' => [$anna->id, $bob->id]]);

        $this->artisan('hrms:report-digests', ['--tenant' => $this->acme()->id])
            ->assertSuccessful();

        foreach ([$anna, $bob] as $recipient) {
            $row = UserNotification::query()
                ->where('user_id', $recipient->id)
                ->where('type', 'hrms.inbox.digest')
                ->firstOrFail();

            $this->assertSame($schedule->id, $row->data['report_schedule_id']);
        }

        $this->assertTrue($schedule->refresh()->next_run_at->greaterThan(now()));

        $this->artisan('hrms:report-digests', ['--tenant' => $this->acme()->id])
            ->assertSuccessful();

        $this->assertSame(2, UserNotification::query()->where('type', 'hrms.inbox.digest')->count());
    }

    public function test_roles_resolve_at_send_time_and_pay_never_digests(): void
    {
        $role = Role::create(['name' => 'Digest Readers', 'slug' => 'digest-readers']);
        $member = $this->makeUser('Digest Member');
        $member->roles()->sync([$role->id]);

        $schedule = $this->schedule(['roles' => ['digest-readers']], ['payroll', 'headcount', 'nope']);

        $built = app(ReportDigestService::class)->build($schedule);

        $this->assertArrayHasKey('headcount', $built);
        $this->assertArrayNotHasKey('payroll', $built);
        $this->assertArrayNotHasKey('nope', $built);

        $this->artisan('hrms:report-digests', ['--tenant' => $this->acme()->id])
            ->assertSuccessful();

        $this->assertTrue(UserNotification::query()
            ->where('user_id', $member->id)
            ->where('type', 'hrms.inbox.digest')
            ->exists());
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $this->makeUser('Digest Dry');
        $this->schedule(['users' => [1]]);

        $this->artisan('hrms:report-digests', ['--tenant' => $this->acme()->id, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, UserNotification::query()->where('type', 'hrms.inbox.digest')->count());
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $recipients
     * @param  list<string>  $sections
     */
    private function schedule(array $recipients, array $sections = ['headcount']): ReportSchedule
    {
        $this->connectTenant('acme');

        return ReportSchedule::create([
            'name' => 'Weekly Digest',
            'slug' => 'weekly-digest',
            'definition' => ['sections' => $sections],
            'cadence' => 'weekly',
            'recipients' => $recipients,
            'next_run_at' => now()->subHour(),
            'is_active' => true,
        ]);
    }

    private function makeUser(string $name): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        return User::create([
            'name' => "{$name} {$sequence}",
            'email' => 'digest.'.str_replace(' ', '.', strtolower($name)).".{$sequence}@flowsync.test",
            'password' => 'password',
        ]);
    }
}
