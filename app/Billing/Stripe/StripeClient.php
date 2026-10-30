<?php

declare(strict_types=1);

namespace App\Billing\Stripe;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The one place that talks HTTP to Stripe: bearer auth, form encoding, a timeout, bounded retries on transient failures
 * (connection errors, 429, 5xx) and `Idempotency-Key` on every write so a retry can never create a second customer,
 * price or subscription. Failures surface as BillingProviderException.
 */
final class StripeClient
{
    public const API_URL = 'https://api.stripe.com/v1';

    public function __construct(private readonly string $secret) {}

    public function configured(): bool
    {
        return $this->secret !== '';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        if (! $this->configured()) {
            throw new BillingProviderException('Stripe is not configured.', false);
        }

        $attempts = max(1, (int) config('billing.stripe.attempts', 3));
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $request = Http::withToken($this->secret)->asForm()->timeout((int) config('billing.stripe.timeout', 20));
                if ($method !== 'get' && $idempotencyKey !== null) {
                    // Same key + different parameters is a Stripe error, so the key carries a fingerprint of the call.
                    $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey.':'.substr(sha1(json_encode($params)), 0, 16)]);
                }
                $response = match ($method) {
                    'get' => $request->get(self::API_URL.$path, $params),
                    'delete' => $request->delete(self::API_URL.$path, $params),
                    default => $request->post(self::API_URL.$path, $params),
                };
            } catch (ConnectionException $e) {
                $last = new BillingProviderException('Stripe connection failed: '.$e->getMessage(), true);
                $this->pause($attempt);

                continue;
            }

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            $status = $response->status();
            $code = $response->json('error.code');
            $message = (string) ($response->json('error.message') ?? $response->body());
            $last = new BillingProviderException("Stripe error ({$status}): {$message}", $status === 429 || $status >= 500, $status, is_string($code) ? $code : null);

            if (! $last->retryable) {
                break;
            }
            $this->pause($attempt);
        }

        Log::warning('Stripe call failed', ['method' => $method, 'path' => $path, 'error' => $last?->getMessage()]);

        throw $last ?? new BillingProviderException('Stripe call failed.', true);
    }

    private function pause(int $attempt): void
    {
        $ms = (int) config('billing.stripe.retry_sleep_ms', 250);
        if ($ms > 0) {
            usleep($ms * 1000 * $attempt);
        }
    }
}
