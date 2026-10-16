<?php

namespace Tests\Feature;

use App\Mail\TaskDigestMail;
use App\Mail\TaskNotificationMail;
use App\Models\NotificationDigestItem;
use App\Models\NotificationPolicy;
use App\Models\NotificationPreference;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F1.5 (M-F4) — tenant email policy, digest mode, dedupe window and email throttle. */
class NotificationDeliveryControlsTest extends TestCase
{
    use IsolatesDatabase;

    private User $recipient;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTenant('acme');
        $this->recipient = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $this->actor = User::where('email', 'admin@flowsync.test')->firstOrFail();
        Mail::fake();
    }

    private function notify(string $type = 'task.assigned', array $extra = []): UserNotification
    {
        return app(NotificationDispatcher::class)->notify($this->recipient, $type, $extra + [
            'task_id' => 1, 'key' => 'APP-1', 'title' => 'Ship it', 'project_id' => 1, 'project_name' => 'App', 'workspace_id' => 1,
        ], $this->actor);
    }

    public function test_an_instant_user_gets_one_mail_per_event(): void
    {
        $this->notify();

        Mail::assertQueued(TaskNotificationMail::class, 1);
    }

    public function test_a_tenant_policy_switches_an_event_off_for_everyone_but_keeps_the_in_app_row(): void
    {
        NotificationPolicy::create(['event' => 'task.assigned', 'channel' => 'email', 'enabled' => false]);

        $this->notify();

        Mail::assertNothingQueued();
        $this->assertSame(1, UserNotification::where('user_id', $this->recipient->id)->where('type', 'task.assigned')->count());
    }

    public function test_digest_mode_holds_mail_and_the_command_sends_one_email(): void
    {
        NotificationPreference::create(['user_id' => $this->recipient->id, 'preferences' => [], 'email_mode' => 'digest']);

        $this->notify('task.assigned');
        $this->notify('task.commented', ['comment_id' => 5, 'snippet' => 'hello']);

        Mail::assertNothingQueued();
        $this->assertSame(2, NotificationDigestItem::whereNull('sent_at')->count());

        $tenantId = Tenant::where('slug', 'acme')->value('id');
        $this->artisan('notifications:send-digests', ['--tenant' => $tenantId])->assertExitCode(0);

        Mail::assertQueued(TaskDigestMail::class, fn (TaskDigestMail $m) => count($m->items) === 2);
        $this->assertSame(0, NotificationDigestItem::whereNull('sent_at')->count());

        // Idempotent: nothing left to send the second time.
        $this->artisan('notifications:send-digests', ['--tenant' => $tenantId])->assertExitCode(0);
        Mail::assertQueued(TaskDigestMail::class, 1);
    }

    public function test_an_identical_notification_inside_the_window_is_written_once(): void
    {
        $first = $this->notify();
        $second = $this->notify();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, UserNotification::where('user_id', $this->recipient->id)->count());
        Mail::assertQueued(TaskNotificationMail::class, 1);
    }

    public function test_a_burst_past_the_throttle_overflows_into_the_digest(): void
    {
        config(['notifications.email_throttle' => ['max' => 2, 'per_minutes' => 60]]);
        Cache::flush();

        foreach ([1, 2, 3, 4] as $n) {
            $this->notify('task.assigned', ['task_id' => $n, 'key' => "APP-$n"]);
        }

        Mail::assertQueued(TaskNotificationMail::class, 2);
        $this->assertSame(2, NotificationDigestItem::whereNull('sent_at')->count());
    }

    public function test_preferences_api_reads_and_writes_the_email_mode(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertOk();

        $this->getJson('/api/notification-preferences')->assertOk()->assertJsonPath('email_mode', 'instant');
        $this->putJson('/api/notification-preferences', ['email_mode' => 'digest'])->assertOk()->assertJsonPath('email_mode', 'digest');
        $this->putJson('/api/notification-preferences', ['email_mode' => 'weekly'])->assertUnprocessable();
    }

    public function test_only_tenant_managers_edit_the_policy_and_unknown_events_are_refused(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertOk();
        $this->getJson('/api/notification-policies')->assertForbidden();

        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();

        // Event names contain a dot, so read the key literally (assertJsonPath treats the dot as nesting).
        $policies = $this->putJson('/api/notification-policies', ['policies' => ['task.assigned' => false]])
            ->assertOk()->json('policies');
        $this->assertFalse($policies['task.assigned']);
        $this->putJson('/api/notification-policies', ['policies' => ['task.exploded' => false]])->assertStatus(422);
    }
}
