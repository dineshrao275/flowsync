<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Events\NotificationSent;
use App\Models\Hrms\Employee\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Hrms\OnboardingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P4.5 — onboarding due notifications, on creation and on schedule.
 *
 * What is worth protecting here is the addressing, not the copy: the owner
 * always hears about their own item, the manage-holders additionally hear
 * about hr-scoped items (a pool nobody nudges is a pile nobody works), far
 * items stay quiet, and the daily command never re-pings the same item
 * within a week. The broadcast itself is faked — the ledger row is the
 * assertion, because a toast nobody stored is a notification nobody can
 * re-read.
 */
class HrmsLifecycleNotificationsTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([NotificationSent::class]);
    }

    public function test_creation_nudges_the_starting_pile_only(): void
    {
        $service = app(OnboardingService::class);
        $manager = $this->userWith(['hrms.view', 'hrms.onboarding.manage']);
        $hire = $this->makeEmployee(['joining_date' => today()->toDateString()]);
        $template = $service->createTemplate('Hire', ['tasks' => [
            $this->task(['title' => 'Day one HR', 'owner_scope' => 'hr', 'due_offset_days' => 1]),
            $this->task(['title' => 'Far future', 'owner_scope' => 'hr', 'due_offset_days' => 60]),
        ]]);

        $service->createCase($hire, $template, $this->admin());

        // `pluck('data->title')` is not portable on the query builder, so the
        // JSON is read row by row: the selector the *command* relies on
        // (`where('data->case_task_id', …)`) is covered by the reminders
        // tests below instead.
        $titles = UserNotification::query()->where('type', 'hrms.onboarding.task_due')->get()
            ->map(fn (UserNotification $note) => $note->data['title'] ?? null)
            ->all();

        $this->assertContains('Day one HR', $titles);
        $this->assertNotContains('Far future', $titles);

        // The hr-scoped item reaches the manage pool, not just the admin who
        // happened to click “start case”.
        $recipients = UserNotification::query()->where('type', 'hrms.onboarding.task_due')->pluck('user_id')->all();

        $this->assertContains($manager->id, $recipients);
    }

    public function test_an_owner_hears_about_their_own_item_but_not_twice_for_self_assignment(): void
    {
        $service = app(OnboardingService::class);
        $owner = $this->userWith(['hrms.view']);
        $hire = $this->makeEmployee(['user_id' => $owner->id, 'joining_date' => today()->toDateString()]);
        $template = $service->createTemplate('Hire', ['tasks' => [
            $this->task(['title' => 'Your paperwork', 'owner_scope' => 'employee', 'due_offset_days' => 1]),
        ]]);

        // The actor IS the owner here: assigning yourself work you already
        // know about must not ping you about it.
        $service->createCase($hire, $template, $owner);

        $this->assertSame(0, UserNotification::query()
            ->where('type', 'hrms.onboarding.task_due')
            ->where('user_id', $owner->id)
            ->count());

        $other = $this->userWith(['hrms.view']);
        $hire2 = $this->makeEmployee(['user_id' => $other->id, 'joining_date' => today()->toDateString()]);
        $service->createCase($hire2, $template, $this->admin());

        $mine = UserNotification::query()
            ->where('type', 'hrms.onboarding.task_due')
            ->where('user_id', $other->id)
            ->first();

        $this->assertNotNull($mine);
        $this->assertSame('Your paperwork', $mine->data['title']);
        $this->assertArrayHasKey('onboarding_case_id', $mine->data);
    }

    public function test_the_reminders_command_notifies_due_items_once_per_week(): void
    {
        $service = app(OnboardingService::class);
        $owner = $this->userWith(['hrms.view']);
        $hire = $this->makeEmployee(['user_id' => $owner->id, 'joining_date' => today()->toDateString()]);
        // Far out of every window, so creation stays quiet and the command
        // is the only thing that could speak.
        $template = $service->createTemplate('Hire', ['tasks' => [
            $this->task(['title' => 'Ripening item', 'owner_scope' => 'employee', 'due_offset_days' => 60]),
        ]]);
        $case = $service->createCase($hire, $template, $this->admin());
        $task = $case->tasks()->firstOrFail();

        $this->assertSame(0, $this->taskDueCount());

        // Ripen it into the window by hand: the command reads due dates, it
        // does not move them.
        $task->update(['due_date' => today()->addDay()->toDateString()]);

        $this->assertSame(0, Artisan::call('hrms:onboarding-reminders', ['--tenant' => $this->acme()->id]));
        $this->assertSame(1, $this->taskDueCount());

        // A second run the next morning finds the same item — and stays
        // quiet, because a notification that arrives daily regardless of
        // action is wallpaper.
        $this->assertSame(0, Artisan::call('hrms:onboarding-reminders', ['--tenant' => $this->acme()->id]));
        $this->assertSame(1, $this->taskDueCount());
    }

    public function test_the_reminders_dry_run_writes_nothing(): void
    {
        $service = app(OnboardingService::class);
        $owner = $this->userWith(['hrms.view']);
        $hire = $this->makeEmployee(['user_id' => $owner->id, 'joining_date' => today()->toDateString()]);
        $template = $service->createTemplate('Hire', ['tasks' => [
            $this->task(['title' => 'Ripening item', 'owner_scope' => 'employee', 'due_offset_days' => 60]),
        ]]);
        $case = $service->createCase($hire, $template, $this->admin());
        $case->tasks()->firstOrFail()->update(['due_date' => today()->addDay()->toDateString()]);

        $this->assertSame(0, Artisan::call('hrms:onboarding-reminders', [
            '--tenant' => $this->acme()->id,
            '--dry-run' => true,
        ]));
        $this->assertSame(0, $this->taskDueCount());
    }

    private function taskDueCount(): int
    {
        return UserNotification::query()->where('type', 'hrms.onboarding.task_due')->count();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEmployee(array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-LCN-'.$sequence,
            'name' => "Lifecycle Notify Person {$sequence}",
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->firstOrFail();
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;

        $user = User::create([
            'name' => "Lifecycle Notify User {$sequence}",
            'email' => "lifecycle.notify.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Lifecycle Notify Role {$sequence}",
            'slug' => "lifecycle-notify-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function task(array $overrides = []): array
    {
        return [
            'title' => 'Do the thing',
            'category' => 'task',
            'owner_scope' => 'hr',
            ...$overrides,
        ];
    }
}
