<?php

namespace Tests\Feature;

use App\Events\CommentSynced;
use App\Events\NotificationSent;
use App\Events\SupportMessageSent;
use App\Events\SupportTicketUpdated;
use App\Events\TaskSynced;
use App\Support\Realtime;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Realtime delivery guards: every push is in-request (no queue worker in the
 * path), a dead Reverb server never breaks the write that triggered it, and
 * channel authorization survives `route:cache`.
 */
class RealtimeResilienceTest extends TestCase
{
    public function test_every_realtime_event_is_pushed_in_request(): void
    {
        foreach ([TaskSynced::class, CommentSynced::class, NotificationSent::class, SupportMessageSent::class, SupportTicketUpdated::class] as $event) {
            $this->assertTrue(
                is_subclass_of($event, ShouldBroadcastNow::class),
                "{$event} must be ShouldBroadcastNow: a queued push waits on the worker and breaks 'instant'.",
            );
        }
    }

    public function test_a_failing_broadcast_is_logged_not_thrown(): void
    {
        $this->app->bind(BroadcastFactory::class, fn () => throw new \RuntimeException('reverb down'));
        Log::spy();

        Realtime::send(new class implements ShouldBroadcastNow
        {
            public function broadcastOn(): array
            {
                return [new PrivateChannel('user.1')];
            }
        });

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_channel_callbacks_are_registered_by_the_provider_not_by_the_cached_routes_file(): void
    {
        // `route:cache` (docker/entrypoint.sh) never executes routes/web.php, so a
        // require there left zero channels registered and every /broadcasting/auth 403'd.
        $this->assertDoesNotMatchRegularExpression('/require[^\n]*channels\.php/', file_get_contents(base_path('routes/web.php')) ?: '', 'routes/web.php must not be what loads routes/channels.php');
        $this->assertStringContainsString('routes/channels.php', file_get_contents(app_path('Providers/AppServiceProvider.php')) ?: '');

        $channels = array_keys(Broadcast::driver()->getChannels()->all());

        foreach (['user.{id}', 'project.{id}', 'workspace.{id}', 'support.ticket.{id}', 'support.ticket.{id}.staff'] as $pattern) {
            $this->assertContains($pattern, $channels);
        }
    }

    public function test_the_frontend_authorizes_channels_through_axios_not_the_stale_meta_token(): void
    {
        $echo = file_get_contents(base_path('resources/js/echo.js')) ?: '';

        $this->assertStringContainsString('authorizer: channelAuthorizer', $echo);
    }
}
