<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuditsTenantAdminActions;
use App\Jobs\DeliverWebhook;
use App\Models\DomainEvent;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Events\Consumers\WebhookConsumer;
use App\Services\Webhooks\WebhookUrlGuard;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Tenant admins manage the endpoints their events are delivered to. The secret is shown once. */
class WebhookEndpointController extends Controller
{
    use AuditsTenantAdminActions;

    public function __construct(private readonly WebhookUrlGuard $guard) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'endpoints' => WebhookEndpoint::orderBy('id')->get()->map(fn ($e) => $this->present($e)),
            'catalog' => config('domain_events.catalog'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->guard->assertSafe($data['url']);

        $secret = WebhookEndpoint::newSecret();
        $endpoint = WebhookEndpoint::create([...$data, 'secret' => $secret, 'created_by' => $request->user()->id]);

        $this->auditTenantAdmin($request, 'webhook.created', 'webhook_endpoints', $endpoint->id, null, ['url' => $endpoint->url, 'events' => $endpoint->events]);

        // The one and only time the signing secret is shown.
        return response()->json(['message' => 'Webhook created.', 'endpoint' => $this->present($endpoint), 'secret' => $secret], 201);
    }

    public function update(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $data = $this->validated($request);
        $this->guard->assertSafe($data['url']);
        $before = ['url' => $webhook->url, 'events' => $webhook->events, 'is_active' => $webhook->is_active];

        $reenabled = ($data['is_active'] ?? true) && ! $webhook->is_active;
        $webhook->update($data + ($reenabled ? ['consecutive_failures' => 0, 'disabled_at' => null, 'disabled_reason' => null] : []));

        $this->auditTenantAdmin($request, 'webhook.updated', 'webhook_endpoints', $webhook->id, $before, ['url' => $webhook->url, 'events' => $webhook->events, 'is_active' => $webhook->is_active]);

        return response()->json(['message' => 'Webhook updated.', 'endpoint' => $this->present($webhook->refresh())]);
    }

    public function destroy(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $this->auditTenantAdmin($request, 'webhook.deleted', 'webhook_endpoints', $webhook->id, ['url' => $webhook->url], null);
        $webhook->delete();

        return response()->json(['message' => 'Webhook deleted.']);
    }

    public function rotateSecret(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $secret = WebhookEndpoint::newSecret();
        $webhook->update(['secret' => $secret]);
        $this->auditTenantAdmin($request, 'webhook.secret_rotated', 'webhook_endpoints', $webhook->id, null, null);

        return response()->json(['message' => 'Secret rotated. Update your receiver now.', 'secret' => $secret]);
    }

    /** Sends a `webhook.test` event to this endpoint right now and reports what happened. */
    public function test(WebhookEndpoint $webhook): JsonResponse
    {
        $event = new DomainEvent([
            'uuid' => (string) Str::uuid(), 'type' => 'webhook.test', 'data' => ['message' => 'This is a test event from FlowSync.'],
            'occurred_at' => now(),
        ]);
        $tenantId = app(TenantContext::class)->currentId();

        $delivery = $webhook->deliveries()->create([
            'event_uuid' => $event->uuid, 'event_type' => 'webhook.test',
            'payload' => WebhookConsumer::payload($event, $tenantId ? Tenant::find($tenantId) : null),
        ]);
        DeliverWebhook::dispatchSync($delivery->id);

        return response()->json(['delivery' => $this->presentDelivery($delivery->refresh())]);
    }

    public function deliveries(WebhookEndpoint $webhook): JsonResponse
    {
        return response()->json(['deliveries' => $webhook->deliveries()->orderByDesc('id')->limit(50)->get()->map(fn ($d) => $this->presentDelivery($d))]);
    }

    public function redeliver(WebhookDelivery $delivery): JsonResponse
    {
        $delivery->update(['status' => 'pending', 'attempts' => 0, 'error' => null, 'next_attempt_at' => null]);
        DeliverWebhook::dispatch($delivery->id);

        return response()->json(['message' => 'Queued for redelivery.', 'delivery' => $this->presentDelivery($delivery->refresh())]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'description' => ['nullable', 'string', 'max:255'],
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*' => ['string', 'max:80', 'regex:/^(\*|[a-z0-9_.]+(\.\*)?)$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(WebhookEndpoint $e): array
    {
        return [
            'id' => $e->id, 'url' => $e->url, 'description' => $e->description, 'events' => $e->events,
            'is_active' => $e->is_active, 'consecutive_failures' => $e->consecutive_failures,
            'disabled_reason' => $e->disabled_reason, 'created_at' => $e->created_at?->toIso8601String(),
            'last_delivery' => $e->deliveries()->latest('id')->first(['status', 'response_status', 'created_at']),
        ];
    }

    /** @return array<string, mixed> */
    private function presentDelivery(WebhookDelivery $d): array
    {
        return [
            'id' => $d->id, 'event_type' => $d->event_type, 'status' => $d->status, 'attempts' => $d->attempts,
            'response_status' => $d->response_status, 'response_excerpt' => $d->response_excerpt, 'error' => $d->error,
            'next_attempt_at' => $d->next_attempt_at?->toIso8601String(), 'delivered_at' => $d->delivered_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
