<?php

namespace Tests\Feature;

use App\Contracts\Notifications\NotificationSender;
use App\Events\NotificationSent;
use App\Mail\TaskNotificationMail;
use App\Models\User;
use App\Models\UserSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Email copy follows the recipient's language (i18n Phase 6). The task mail
 * resolves its subject/line from `lang/<locale>/notifications.php` and pins the
 * rendering locale, so a Spanish-preferring user gets Spanish mail while the
 * request that triggered it stays in the actor's language.
 */
class EmailLocaleTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();

        $this->connectTenant('acme');
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function preferLocale(User $user, string $locale): void
    {
        $settings = UserSettings::firstOrCreate(['user_id' => $user->id]);
        $settings->update(['settings' => array_merge($settings->settings ?? [], ['locale' => $locale])]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'key' => 'WEB-1',
            'title' => 'Ship it',
            'project_name' => 'Website',
            'project_id' => 1,
            'workspace_id' => 1,
            'to_status' => 'Done',
        ];
    }

    public function test_the_subject_and_line_follow_the_given_locale(): void
    {
        $english = TaskNotificationMail::fromData('task.assigned', 'Ana', $this->payload(), 'http://x', 'en');
        $this->assertSame('[WEB-1] You were assigned Ship it', $english->subjectText);
        $this->assertSame('Ana assigned WEB-1 — Ship it to you', $english->line);

        $spanish = TaskNotificationMail::fromData('task.assigned', 'Ana', $this->payload(), 'http://x', 'es');
        $this->assertSame('[WEB-1] Se te asignó Ship it', $spanish->subjectText);
        $this->assertSame('Ana te asignó WEB-1 — Ship it', $spanish->line);
    }

    public function test_the_rendered_body_uses_the_locale_labels(): void
    {
        $mail = TaskNotificationMail::fromData('task.status_changed', 'Ana', $this->payload(), 'http://x', 'es');

        $rendered = $mail->render();

        $this->assertStringContainsString('Proyecto: Website', $rendered);
        $this->assertSame('Done', $mail->task['to_status']);
        $this->assertStringContainsString('Abrir tarea', $rendered);
    }

    public function test_an_unknown_actor_falls_back_to_the_localised_placeholder(): void
    {
        $spanish = TaskNotificationMail::fromData('task.commented', '', $this->payload(), 'http://x', 'es');

        $this->assertSame('Alguien comentó en WEB-1 — Ship it', $spanish->line);
    }

    public function test_the_dispatcher_uses_each_recipients_own_language(): void
    {
        Mail::fake();
        Event::fake([NotificationSent::class]);
        $this->login('admin@flowsync.test');

        $actor = $this->user('admin@flowsync.test');
        $english = $this->user('viewer@flowsync.test');
        $spanish = $this->user('editor@flowsync.test');
        $this->preferLocale($spanish, 'es');

        $sender = app(NotificationSender::class);
        $sender->notify($english, 'task.assigned', $this->payload(), $actor);
        $sender->notify($spanish, 'task.assigned', $this->payload(), $actor);

        Mail::assertQueued(TaskNotificationMail::class, fn (TaskNotificationMail $mail) => $mail->hasTo($english->email)
            && $mail->subjectText === '[WEB-1] You were assigned Ship it');

        Mail::assertQueued(TaskNotificationMail::class, fn (TaskNotificationMail $mail) => $mail->hasTo($spanish->email)
            && $mail->subjectText === '[WEB-1] Se te asignó Ship it');
    }

    public function test_mail_copy_falls_back_to_the_current_locale_without_a_preference(): void
    {
        Mail::fake();
        Event::fake([NotificationSent::class]);
        $this->login('admin@flowsync.test');

        // The request/tenant resolved to Spanish; the recipient has no preference.
        app()->setLocale('es');

        app(NotificationSender::class)->notify($this->user('editor@flowsync.test'), 'task.assigned', $this->payload(), $this->user('admin@flowsync.test'));

        Mail::assertQueued(TaskNotificationMail::class, fn (TaskNotificationMail $mail) => $mail->hasTo($this->user('editor@flowsync.test')->email)
            && $mail->subjectText === '[WEB-1] Se te asignó Ship it');
    }
}
