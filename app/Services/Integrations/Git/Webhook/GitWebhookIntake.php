<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git\Webhook;

use App\Jobs\ProcessGitWebhook;
use App\Models\GitConnection;
use App\Models\GitWebhookDelivery;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The webhook hot path: verify, record, route, queue - and nothing else. No provider API call and no
 * tenant database is touched here. Every refusal looks the same to the sender (a bare 404/202) so the
 * endpoint cannot be used to learn which installations or tenants exist.
 */
class GitWebhookIntake
{
    /** Bodies above this are recorded but not queued: a push to a giant repo must not fill the queue table. */
    public const MAX_QUEUED_BYTES = 1_000_000;

    /** @return array{status: int, body: array<string, mixed>} */
    public function accept(Request $request): array
    {
        $secret = (string) config('git.providers.github.webhook_secret');
        if (! config('git.enabled') || $secret === '') {
            return $this->reply(404);
        }

        $raw = $request->getContent();
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        if (! hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature)) {
            return $this->reply(404);
        }

        $event = Str::limit(preg_replace('/[^a-z_]/', '', strtolower((string) $request->header('X-GitHub-Event', ''))) ?? '', 60, '');
        $uuid = (string) $request->header('X-GitHub-Delivery', '');
        if ($event === '' || ! preg_match('/^[A-Za-z0-9-]{8,64}$/', $uuid)) {
            return $this->reply(400, 'invalid');
        }

        $payload = json_decode($raw, true);
        $payload = is_array($payload) ? $payload : null;

        $delivery = GitWebhookDelivery::query()->firstOrCreate(['delivery_uuid' => $uuid], [
            'event' => $event,
            'action' => is_string($payload['action'] ?? null) ? Str::limit($payload['action'], 60, '') : null,
            'status' => GitWebhookDelivery::STATUS_RECEIVED,
            'payload_bytes' => strlen($raw),
            'payload_excerpt' => $payload === null ? null : $this->excerpt($event, $payload),
            'received_at' => now(),
        ]);
        if (! $delivery->wasRecentlyCreated) {
            return $this->reply(202, 'duplicate');
        }

        if ($payload === null) {
            return $this->settle($delivery, GitWebhookDelivery::STATUS_IGNORED, 'Malformed payload.');
        }
        if ($event === 'ping') {
            $this->settle($delivery, GitWebhookDelivery::STATUS_PROCESSED);

            return $this->reply(200, null, 'pong');
        }

        $connection = $this->connectionFor($payload);
        if ($connection === null) {
            return $this->settle($delivery, GitWebhookDelivery::STATUS_IGNORED, 'No active connection for this installation.');
        }
        $delivery->forceFill(['git_connection_id' => $connection->id, 'tenant_id' => $connection->tenant_id])->save();

        // A suspended connection only listens for the event that un-suspends it.
        if (! $connection->isActive() && $event !== 'installation') {
            return $this->settle($delivery, GitWebhookDelivery::STATUS_IGNORED, 'Connection is not active.');
        }
        if ($event !== 'installation' && ! $this->tenantIsServing($connection->tenant_id)) {
            return $this->settle($delivery, GitWebhookDelivery::STATUS_IGNORED, 'Tenant is not active.');
        }
        if (strlen($raw) > self::MAX_QUEUED_BYTES) {
            return $this->settle($delivery, GitWebhookDelivery::STATUS_IGNORED, 'Payload too large to process.');
        }

        ProcessGitWebhook::dispatch($delivery->id, $event, $payload);

        return $this->reply(202, 'accepted');
    }

    /** @param  array<string, mixed>  $payload */
    private function connectionFor(array $payload): ?GitConnection
    {
        $installationId = (int) ($payload['installation']['id'] ?? 0);
        if ($installationId < 1) {
            return null;
        }

        return GitConnection::query()->where('provider', 'github')->where('installation_id', $installationId)
            ->where('status', '!=', GitConnection::STATUS_REVOKED)->first();
    }

    private function tenantIsServing(int $tenantId): bool
    {
        return Tenant::query()->whereKey($tenantId)->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->exists();
    }

    /** One-line, code-free summary: event, action, repository and sender. Never a body, title or message. */
    private function excerpt(string $event, array $payload): string
    {
        $parts = array_filter([
            $event,
            is_string($payload['action'] ?? null) ? $payload['action'] : null,
            is_string($payload['repository']['full_name'] ?? null) ? $payload['repository']['full_name'] : null,
            is_string($payload['sender']['login'] ?? null) ? 'by '.$payload['sender']['login'] : null,
        ]);

        return Str::limit((string) preg_replace('/[^\P{C}]+/u', '', implode(' ', $parts)), 200, '');
    }

    private function settle(GitWebhookDelivery $delivery, string $status, ?string $error = null): array
    {
        $delivery->forceFill(['status' => $status, 'error' => $error, 'processed_at' => now()])->save();

        return $this->reply(202, $status);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function reply(int $status, ?string $state = null, ?string $message = null): array
    {
        return ['status' => $status, 'body' => array_filter(['status' => $state, 'message' => $message])];
    }
}
