<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\DomainEvent;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Events\DomainEvents;
use App\Services\SubscriptionService;
use App\Services\Webhooks\WebhookSender;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P2.8 — outbound webhooks: management, safety, signing, delivery, retry. */
class WebhookTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['webhooks.resolver' => fn (string $host) => ['93.184.216.34']]); // a public address, no real DNS
    }

    private function login(string $email = 'admin@flowsync.test'): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    private function create(array $over = []): array
    {
        return $this->postJson('/api/webhooks', $over + ['url' => 'https://hooks.example.com/flowsync', 'events' => ['task.*']])->assertCreated()->json();
    }

    public function test_an_admin_creates_an_endpoint_and_sees_the_secret_once(): void
    {
        $this->login();
        $created = $this->create(['description' => 'CI']);

        $this->assertStringStartsWith('whsec_', $created['secret']);
        $list = $this->getJson('/api/webhooks')->assertOk()->json();
        $this->assertArrayNotHasKey('secret', $list['endpoints'][0]);
        $this->assertContains('task.completed', $list['catalog']);
        $this->assertNotSame($created['secret'], WebhookEndpoint::first()->getRawOriginal('secret')); // stored encrypted
    }

    public function test_unsafe_urls_are_refused(): void
    {
        $this->login();

        foreach (['http://hooks.example.com/x' => 'https', 'https://user:pw@hooks.example.com/x' => 'credentials', 'https://hooks.example.com:22/x' => 'port', 'not a url' => 'URL'] as $url => $why) {
            $this->postJson('/api/webhooks', ['url' => $url, 'events' => ['*']])->assertUnprocessable()->assertJsonValidationErrors('url');
        }

        config(['webhooks.resolver' => fn () => ['127.0.0.1']]);
        $this->postJson('/api/webhooks', ['url' => 'https://internal.example.com/x', 'events' => ['*']])->assertUnprocessable();
        config(['webhooks.resolver' => fn () => ['10.0.0.5', '93.184.216.34']]);   // any private answer is enough
        $this->postJson('/api/webhooks', ['url' => 'https://mixed.example.com/x', 'events' => ['*']])->assertUnprocessable();
    }

    public function test_a_matching_event_is_delivered_signed_and_recorded(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(['ok' => true], 200)]);
        $this->login();
        $secret = $this->create()['secret'];

        $event = app(DomainEvents::class)->record('task.completed', 'App\\Models\\Task', 7, ['key' => 'PP-7'], 1);
        app(DomainEvents::class)->record('leave.approved');   // not subscribed

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) use ($secret, $event) {
            [$t, $v1] = array_map(fn ($p) => explode('=', $p, 2)[1], explode(',', $r->header('X-FlowSync-Signature')[0]));
            $body = $r->body();

            return $r->url() === 'https://hooks.example.com/flowsync'
                && $r->header('X-FlowSync-Event')[0] === 'task.completed'
                && hash_equals(WebhookSender::sign($secret, $body, (int) $t), "t={$t},v1={$v1}")
                && json_decode($body, true)['id'] === $event->uuid
                && json_decode($body, true)['data']['key'] === 'PP-7';
        });
        $delivery = WebhookDelivery::firstOrFail();
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(200, $delivery->response_status);
    }

    public function test_a_failing_receiver_is_retried_with_backoff_then_given_up_on(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('nope', 500)]);
        Queue::fake([DeliverWebhook::class]);
        $this->login();
        $this->create();

        app(DomainEvents::class)->record('task.created');
        $delivery = WebhookDelivery::firstOrFail();

        // First attempt: fails and schedules the next (the sync queue is faked so we drive it).
        (new DeliverWebhook($delivery->id))->handle(app(WebhookSender::class));
        $delivery->refresh();
        $this->assertSame('retrying', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(500, $delivery->response_status);
        $this->assertNotNull($delivery->next_attempt_at);

        foreach (range(2, 5) as $_) {
            (new DeliverWebhook($delivery->id))->handle(app(WebhookSender::class));
        }
        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(5, $delivery->attempts);
        $this->assertSame(1, WebhookEndpoint::first()->consecutive_failures);
    }

    public function test_an_endpoint_that_keeps_failing_is_switched_off(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('nope', 500)]);
        config(['webhooks.disable_after_failures' => 2, 'webhooks.retry_backoff_minutes' => []]);
        Queue::fake([DeliverWebhook::class]);
        $this->login();
        $this->create();

        foreach (range(1, 2) as $_) {
            app(DomainEvents::class)->record('task.created');
        }
        foreach (WebhookDelivery::all() as $d) {
            (new DeliverWebhook($d->id))->handle(app(WebhookSender::class));
        }

        $endpoint = WebhookEndpoint::first();
        $this->assertFalse($endpoint->is_active);
        $this->assertStringContainsString('failed deliveries', $endpoint->disabled_reason);

        // Re-enabling through the API resets the counter.
        $this->putJson("/api/webhooks/{$endpoint->id}", ['url' => $endpoint->url, 'events' => ['task.*'], 'is_active' => true])->assertOk();
        $this->assertSame(0, $endpoint->refresh()->consecutive_failures);
        $this->assertTrue($endpoint->is_active);
    }

    public function test_the_test_button_sends_a_test_event_and_reports_the_result(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 204)]);
        $this->login();
        $id = $this->create()['endpoint']['id'];

        $this->postJson("/api/webhooks/{$id}/test")->assertOk()
            ->assertJsonPath('delivery.status', 'delivered')->assertJsonPath('delivery.event_type', 'webhook.test');
    }

    public function test_redeliver_and_rotate_secret(): void
    {
        Http::fake(['hooks.example.com/*' => Http::sequence()->push('x', 500)->push('x', 200)]);
        Queue::fake([DeliverWebhook::class]);
        $this->login();
        $created = $this->create();
        app(DomainEvents::class)->record('task.created');
        $d = WebhookDelivery::firstOrFail();
        (new DeliverWebhook($d->id))->handle(app(WebhookSender::class));

        $this->postJson("/api/webhook-deliveries/{$d->id}/redeliver")->assertOk()->assertJsonPath('delivery.status', 'pending');
        Queue::assertPushed(DeliverWebhook::class);

        $new = $this->postJson("/api/webhooks/{$created['endpoint']['id']}/rotate-secret")->assertOk()->json('secret');
        $this->assertNotSame($created['secret'], $new);
        $this->assertSame($new, WebhookEndpoint::first()->secret);
    }

    public function test_it_needs_the_permission_and_the_plan_module(): void
    {
        $this->login('viewer@flowsync.test');
        $this->getJson('/api/webhooks')->assertForbidden();
        $this->postJson('/api/auth/logout');

        $acme = Tenant::where('slug', 'acme')->firstOrFail();
        $starter = SubscriptionPlan::where('slug', 'starter')->firstOrFail();
        app(SubscriptionService::class)->assign($acme, $starter);
        $this->login();
        $this->getJson('/api/webhooks')->assertForbidden()->assertHeader('X-Module-Denied', 'webhooks');
    }

    public function test_events_are_tenant_scoped(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
        $this->login();
        $this->create();

        // An event recorded inside the OTHER tenant's database never reaches acme's endpoint.
        $globex = Tenant::where('slug', 'globex')->firstOrFail();
        $this->dbm->using($globex, fn () => app(DomainEvents::class)->record('task.created'));

        Http::assertNothingSent();
        $acme = $this->acme();
        $this->assertSame(0, $this->dbm->using($acme, fn () => DomainEvent::count()));
        $this->assertSame(0, $this->dbm->using($acme, fn () => WebhookDelivery::count()));
        $this->assertSame(1, $this->dbm->using($globex, fn () => DomainEvent::count()));
    }
}
