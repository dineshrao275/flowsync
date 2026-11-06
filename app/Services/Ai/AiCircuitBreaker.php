<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Exceptions\AiErrorKind;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Cache;

/**
 * Stops hammering a provider that is down. Only outage-type failures count (unreachable, 5xx, timeout):
 * a bad key, a refused request or a rate limit would fail the same way for every caller and are
 * not evidence of an outage. State lives in the shared cache so every PHP worker agrees.
 */
final class AiCircuitBreaker
{
    public function assertClosed(string $provider): void
    {
        if (Cache::has($this->key($provider, 'open'))) {
            throw new AiProviderException(AiErrorKind::Unavailable, "{$provider} is temporarily paused after repeated failures.", $provider);
        }
    }

    public function failure(string $provider, AiErrorKind $kind): void
    {
        if (! in_array($kind, [AiErrorKind::Unavailable, AiErrorKind::Timeout], true)) {
            return;
        }

        $count = $this->key($provider, 'count');
        Cache::add($count, 0, (int) config('ai.breaker_window_seconds'));

        if ((int) Cache::increment($count) >= (int) config('ai.breaker_threshold')) {
            Cache::put($this->key($provider, 'open'), true, (int) config('ai.breaker_cooldown_seconds'));
            Cache::forget($count);
        }
    }

    public function success(string $provider): void
    {
        Cache::forget($this->key($provider, 'count'));
    }

    private function key(string $provider, string $part): string
    {
        return "ai:breaker:{$provider}:{$part}";
    }
}
