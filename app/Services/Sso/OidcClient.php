<?php

namespace App\Services\Sso;

use App\Services\Webhooks\WebhookUrlGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A minimal OpenID Connect relying party (authorization-code flow with PKCE). It does exactly what a login needs and
 * nothing more: discovery, the authorization URL, the code exchange, and — the part that must not be wrong — verifying
 * the ID token: RS256 only (never `none`, never an HMAC keyed with a public value), signature against the provider's
 * JWKS, issuer, audience, expiry and the nonce we sent. Every outbound URL passes the same SSRF guard as webhooks.
 */
class OidcClient
{
    private const CLOCK_SKEW = 60;

    public function __construct(private readonly WebhookUrlGuard $guard) {}

    /** @return array{issuer: string, authorization_endpoint: string, token_endpoint: string, jwks_uri: string} */
    public function discover(string $issuer): array
    {
        $issuer = rtrim($issuer, '/');

        return Cache::remember('sso:discovery:'.sha1($issuer), 3600, function () use ($issuer): array {
            $this->guard->assertSafe($issuer, 'issuer');
            $doc = Http::timeout(8)->acceptJson()->get($issuer.'/.well-known/openid-configuration')->throw()->json();

            foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
                if (! is_string($doc[$key] ?? null)) {
                    throw new RuntimeException("The identity provider's discovery document has no {$key}.");
                }
                $key === 'issuer' ?: $this->guard->assertSafe($doc[$key], $key);
            }

            return array_intersect_key($doc, array_flip(['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri']));
        });
    }

    public function authorizationUrl(array $discovery, string $clientId, string $redirectUri, string $state, string $nonce, string $verifier): string
    {
        return $discovery['authorization_endpoint'].(str_contains($discovery['authorization_endpoint'], '?') ? '&' : '?').http_build_query([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'scope' => 'openid email profile',
            'state' => $state, 'nonce' => $nonce, 'code_challenge' => self::b64(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
        ]);
    }

    /** Swap the authorization code for an ID token. */
    public function exchange(array $discovery, string $clientId, string $clientSecret, string $redirectUri, string $code, string $verifier): string
    {
        $res = Http::timeout(10)->asForm()->acceptJson()->post($discovery['token_endpoint'], [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri,
            'client_id' => $clientId, 'client_secret' => $clientSecret, 'code_verifier' => $verifier,
        ])->throw()->json();

        if (! is_string($res['id_token'] ?? null)) {
            throw new RuntimeException('The identity provider returned no ID token.');
        }

        return $res['id_token'];
    }

    /**
     * @return array<string, mixed> the verified claims
     *
     * @throws RuntimeException on any failed check
     */
    public function verifyIdToken(string $jwt, array $discovery, string $clientId, string $nonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed ID token.');
        }

        $header = json_decode(self::unb64($parts[0]), true);
        $claims = json_decode(self::unb64($parts[1]), true);
        if (! is_array($header) || ! is_array($claims)) {
            throw new RuntimeException('Malformed ID token.');
        }
        if (($header['alg'] ?? null) !== 'RS256') {
            throw new RuntimeException('Unsupported signing algorithm.');
        }

        $pem = $this->keyFor($discovery['jwks_uri'], $header['kid'] ?? null);
        if (openssl_verify($parts[0].'.'.$parts[1], self::unb64($parts[2]), $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('The ID token signature is not valid.');
        }

        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim($discovery['issuer'], '/')) {
            throw new RuntimeException('Wrong issuer.');
        }
        $aud = (array) ($claims['aud'] ?? []);
        if (! in_array($clientId, $aud, true) || (count($aud) > 1 && ($claims['azp'] ?? null) !== $clientId)) {
            throw new RuntimeException('Wrong audience.');
        }
        if (($claims['exp'] ?? 0) < time() - self::CLOCK_SKEW) {
            throw new RuntimeException('The ID token has expired.');
        }
        if (! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new RuntimeException('Wrong nonce.');
        }

        return $claims;
    }

    private function keyFor(string $jwksUri, ?string $kid): string
    {
        $keys = Cache::remember('sso:jwks:'.sha1($jwksUri), 600, fn () => Http::timeout(8)->acceptJson()->get($jwksUri)->throw()->json('keys') ?? []);

        foreach ((array) $keys as $jwk) {
            if (($jwk['kty'] ?? null) === 'RSA' && ($kid === null || ($jwk['kid'] ?? null) === $kid) && isset($jwk['n'], $jwk['e'])) {
                return self::pemFromJwk($jwk['n'], $jwk['e']);
            }
        }

        // A rotated key: forget the cached set so the next attempt refetches.
        Cache::forget('sso:jwks:'.sha1($jwksUri));

        throw new RuntimeException('No matching signing key.');
    }

    /** Build a PEM public key from a JWK's modulus and exponent (RFC 7517 → X.509 SubjectPublicKeyInfo). */
    public static function pemFromJwk(string $n, string $e): string
    {
        $int = function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");
            if ($bytes === '' || ord($bytes[0]) > 0x7F) {
                $bytes = "\x00".$bytes;
            }

            return "\x02".self::derLength(strlen($bytes)).$bytes;
        };
        $seq = fn (string $body): string => "\x30".self::derLength(strlen($body)).$body;

        $rsaKey = $seq($int(self::unb64($n)).$int(self::unb64($e)));
        $algorithm = $seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $bitString = "\x03".self::derLength(strlen($rsaKey) + 1)."\x00".$rsaKey;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($seq($algorithm.$bitString)), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    }
}
