<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * The `state` that travels out to GitHub and back on the setup redirect. It is encrypted (so the
 * tenant and user cannot be edited), expires after ten minutes and carries a nonce that can be
 * redeemed once, so a leaked or replayed redirect URL connects nothing.
 */
final class GitInstallState
{
    public const TTL_SECONDS = 600;

    public function issue(int $tenantId, int $userId): string
    {
        $nonce = Str::random(32);
        Cache::put($this->key($nonce), true, self::TTL_SECONDS);

        return Crypt::encryptString((string) json_encode([
            't' => $tenantId, 'u' => $userId, 'n' => $nonce, 'exp' => time() + self::TTL_SECONDS,
        ]));
    }

    /**
     * Validates and burns the state.
     *
     * @return array{tenant_id: int, user_id: int}
     *
     * @throws GitConnectionException
     */
    public function redeem(string $state): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($state), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw $this->invalid();
        }

        if (! is_array($payload) || ($payload['exp'] ?? 0) < time() || ! is_string($payload['n'] ?? null)
            || ! Cache::pull($this->key($payload['n']))) {
            throw $this->invalid();
        }

        return ['tenant_id' => (int) $payload['t'], 'user_id' => (int) $payload['u']];
    }

    private function key(string $nonce): string
    {
        return "git:state:{$nonce}";
    }

    private function invalid(): GitConnectionException
    {
        return new GitConnectionException(__('This connection link has expired. Start the connection again from Integrations.'), 422);
    }
}
