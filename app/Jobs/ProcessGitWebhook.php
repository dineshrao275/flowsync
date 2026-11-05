<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\GitConnection;
use App\Models\GitWebhookDelivery;
use App\Models\Tenant;
use App\Services\Integrations\Git\Webhook\GitWebhookProcessor;
use App\Support\TenantDatabaseManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Processes one recorded delivery inside its tenant's database. It records its own outcome and never
 * throws: a failing handler is a `failed` delivery row (visible to the platform), not a retry storm.
 */
class ProcessGitWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const FAILURE_ALARM = 5;

    public int $tries = 1;

    /** @param  array<string, mixed>  $payload */
    public function __construct(public int $deliveryId, public string $event, public array $payload) {}

    public function handle(GitWebhookProcessor $processor, TenantDatabaseManager $databases): void
    {
        $delivery = GitWebhookDelivery::query()->find($this->deliveryId);
        if ($delivery === null || $delivery->status !== GitWebhookDelivery::STATUS_RECEIVED) {
            return;
        }

        try {
            $connection = GitConnection::query()->findOrFail($delivery->git_connection_id);
            $tenant = Tenant::query()->findOrFail($connection->tenant_id);
            $status = $databases->using($tenant, fn () => $processor->handle($connection, $this->event, $this->payload, $delivery->delivery_uuid));

            $delivery->forceFill(['status' => $status, 'processed_at' => now()])->save();
            Log::info('git.webhook.processed', ['delivery' => $delivery->delivery_uuid, 'event' => $this->event, 'status' => $status]);
        } catch (Throwable $e) {
            // Class and message only: the payload (PR text, commit messages) is never logged.
            $delivery->forceFill(['status' => GitWebhookDelivery::STATUS_FAILED, 'error' => Str::limit($e::class.': '.$e->getMessage(), 500), 'processed_at' => now()])->save();
            Log::error('git.webhook.failed', ['delivery' => $delivery->delivery_uuid, 'event' => $this->event, 'error' => $e::class]);
            $this->alarmOnRepeatedFailures($delivery);
        }
    }

    /** Five failures in an hour for one connection is an incident, not noise: one critical line when the threshold is crossed. */
    private function alarmOnRepeatedFailures(GitWebhookDelivery $delivery): void
    {
        $recent = GitWebhookDelivery::query()->where('git_connection_id', $delivery->git_connection_id)
            ->where('status', GitWebhookDelivery::STATUS_FAILED)->where('received_at', '>=', now()->subHour())->count();
        if ($recent === self::FAILURE_ALARM) {
            Log::critical('git.webhook.repeated_failures', ['connection_id' => $delivery->git_connection_id, 'failures_last_hour' => $recent]);
        }
    }
}
