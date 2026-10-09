<?php

namespace App\Services\Events\Consumers;

use App\Jobs\DeliverWebhook;
use App\Models\DomainEvent;
use App\Models\Tenant;
use App\Models\WebhookEndpoint;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Schema;

/** Turns a recorded event into one pending delivery per subscribed endpoint and queues them. */
class WebhookConsumer
{
    public function handle(DomainEvent $event): void
    {
        if (! Schema::hasTable('webhook_endpoints')) {
            return; // a tenant database that predates webhooks
        }

        $endpoints = WebhookEndpoint::where('is_active', true)->get()->filter(fn (WebhookEndpoint $e) => $e->wants($event->type));
        if ($endpoints->isEmpty()) {
            return;
        }

        $tenantId = app(TenantContext::class)->currentId();
        $tenant = $tenantId ? Tenant::find($tenantId) : null;

        foreach ($endpoints as $endpoint) {
            $delivery = $endpoint->deliveries()->create([
                'event_uuid' => $event->uuid,
                'event_type' => $event->type,
                'payload' => self::payload($event, $tenant),
            ]);
            DeliverWebhook::dispatch($delivery->id);
        }
    }

    /** @return array<string, mixed> the stable envelope integrations receive */
    public static function payload(DomainEvent $event, ?Tenant $tenant): array
    {
        return [
            'id' => $event->uuid,
            'type' => $event->type,
            'created_at' => $event->occurred_at?->toIso8601String(),
            'tenant' => $tenant ? ['id' => $tenant->id, 'slug' => $tenant->slug] : null,
            'actor_user_id' => $event->actor_user_id,
            'subject' => ['type' => $event->subject_type ? class_basename($event->subject_type) : null, 'id' => $event->subject_id],
            'data' => $event->data ?? (object) [],
        ];
    }
}
