<?php

namespace App\Services\Webhooks;

use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One signed HTTP POST. The body is the JSON event; `X-FlowSync-Signature` is
 * `t=<unix>,v1=<hmac_sha256(secret, "<t>.<body>")>` so a receiver can verify the sender and
 * reject replays by checking the timestamp, the same scheme payment providers use.
 */
class WebhookSender
{
    public function __construct(private readonly WebhookUrlGuard $guard) {}

    public static function sign(string $secret, string $body, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** @return array{ok: bool, status: int|null, excerpt: string|null, error: string|null} */
    public function send(WebhookDelivery $delivery): array
    {
        $endpoint = $delivery->endpoint;
        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $now = time();

        try {
            $this->guard->assertSafe($endpoint->url);

            $response = Http::timeout((int) config('webhooks.timeout_seconds', 10))
                ->withoutRedirecting()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'FlowSync-Webhooks/1.0',
                    'X-FlowSync-Event' => $delivery->event_type,
                    'X-FlowSync-Delivery' => (string) $delivery->id,
                    'X-FlowSync-Signature' => self::sign($endpoint->secret, $body, $now),
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'excerpt' => mb_substr((string) $response->body(), 0, 500),
                'error' => $response->successful() ? null : 'HTTP '.$response->status(),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => null, 'excerpt' => null, 'error' => mb_substr($e->getMessage(), 0, 250)];
        }
    }
}
