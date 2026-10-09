<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Makes one delivery attempt. A failure schedules the next attempt itself (backoff from
 * config/webhooks.php) instead of relying on queue retries, so every attempt is a row the
 * tenant can see; the endpoint is switched off after a long run of failures.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(WebhookSender $sender): void
    {
        $delivery = WebhookDelivery::with('endpoint')->find($this->deliveryId);
        if (! $delivery || $delivery->status === 'delivered' || ! $delivery->endpoint) {
            return;
        }
        $endpoint = $delivery->endpoint;
        if (! $endpoint->is_active && $delivery->status !== 'pending') {
            return;
        }

        $result = $sender->send($delivery);
        $attempts = $delivery->attempts + 1;

        if ($result['ok']) {
            $delivery->update([
                'status' => 'delivered', 'attempts' => $attempts, 'response_status' => $result['status'],
                'response_excerpt' => $result['excerpt'], 'error' => null, 'delivered_at' => now(), 'next_attempt_at' => null,
            ]);
            $endpoint->update(['consecutive_failures' => 0]);

            return;
        }

        $backoff = config('webhooks.retry_backoff_minutes', [1, 5, 30, 120]);
        $more = $attempts <= count($backoff);
        $delivery->update([
            'status' => $more ? 'retrying' : 'failed', 'attempts' => $attempts, 'response_status' => $result['status'],
            'response_excerpt' => $result['excerpt'], 'error' => $result['error'],
            'next_attempt_at' => $more ? now()->addMinutes($backoff[$attempts - 1]) : null,
        ]);

        if ($more) {
            self::dispatch($delivery->id)->delay(now()->addMinutes($backoff[$attempts - 1]));

            return;
        }

        // Only a delivery that is finally given up on counts against the endpoint.
        $failures = $endpoint->consecutive_failures + 1;
        $endpoint->update(['consecutive_failures' => $failures]);
        if ($failures >= (int) config('webhooks.disable_after_failures', 20)) {
            $endpoint->update(['is_active' => false, 'disabled_at' => now(), 'disabled_reason' => "Switched off after {$failures} failed deliveries in a row."]);
        }
    }
}
