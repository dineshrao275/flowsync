<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use RuntimeException;

/**
 * Signs the short-lived RS256 JWT a GitHub App uses to authenticate AS the app (not as an
 * installation). Hand-rolled with openssl so no dependency is needed; `iat` is backdated 60s
 * to absorb clock skew and `exp` stays inside GitHub's 10-minute ceiling.
 */
final class GitHubAppJwt
{
    public function __construct(private readonly string $appId, private readonly string $privateKey) {}

    /** Reads the key from `private_key` (PEM or `base64:`-prefixed) or `private_key_path`. */
    public static function fromConfig(): self
    {
        $config = (array) config('git.providers.github');
        $key = (string) ($config['private_key'] ?? '');

        if ($key === '' && ! empty($config['private_key_path']) && is_readable((string) $config['private_key_path'])) {
            $key = (string) file_get_contents((string) $config['private_key_path']);
        }
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }
        if (empty($config['app_id']) || $key === '') {
            throw new GitProviderException(__('The GitHub App is not configured on this server.'));
        }

        return new self((string) $config['app_id'], $key);
    }

    public function token(?int $now = null): string
    {
        $now ??= time();
        $header = self::encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::encode((string) json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => $this->appId]));
        $signature = '';

        $key = openssl_pkey_get_private($this->privateKey);
        if ($key === false || ! openssl_sign("{$header}.{$claims}", $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The GitHub App private key could not sign a token.');
        }

        return "{$header}.{$claims}.".self::encode($signature);
    }

    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
