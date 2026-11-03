<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

/**
 * Operator-facing checks of the GitHub App configuration. It never prints a secret: only whether each one is set,
 * long enough, and stored somewhere sensible. Each finding is `[level, message]` with level ok|warn|fail.
 */
class GitConfigAudit
{
    /** Webhook secrets shorter than this are guessable enough to forge deliveries. */
    public const MIN_SECRET_LENGTH = 32;

    /** @return list<array{0: 'ok'|'warn'|'fail', 1: string}> */
    public function run(): array
    {
        $c = (array) config('git.providers.github');
        $out = [[config('git.enabled') ? 'ok' : 'warn', config('git.enabled') ? 'GIT_ENABLED is on.' : 'GIT_ENABLED is off: the feature is invisible to tenants.']];

        $out[] = ! empty($c['app_id']) && ! empty($c['slug']) ? ['ok', 'App id and slug are set.'] : ['fail', 'GITHUB_APP_ID and GITHUB_APP_SLUG must both be set.'];
        array_push($out, ...$this->privateKey($c));

        $secret = (string) ($c['webhook_secret'] ?? '');
        $out[] = match (true) {
            $secret === '' => ['fail', 'GITHUB_APP_WEBHOOK_SECRET is not set: every webhook is refused.'],
            strlen($secret) < self::MIN_SECRET_LENGTH => ['warn', 'GITHUB_APP_WEBHOOK_SECRET is shorter than '.self::MIN_SECRET_LENGTH.' characters; generate a longer one.'],
            default => ['ok', 'Webhook secret is set and long enough.'],
        };

        $out[] = ! empty($c['client_id']) && ! empty($c['client_secret'])
            ? ['ok', 'OAuth client id/secret are set (installer proof works).']
            : ['fail', 'GITHUB_APP_CLIENT_ID / GITHUB_APP_CLIENT_SECRET are missing: no tenant can complete an install.'];

        $api = (string) ($c['api_url'] ?? '');
        $out[] = str_starts_with($api, 'https://') ? ['ok', 'API URL uses https.'] : ['fail', 'GITHUB_APP_API_URL must be an https:// URL.'];
        $out[] = str_starts_with((string) config('app.url'), 'https://') ? ['ok', 'APP_URL is https (GitHub needs a public https webhook URL).'] : ['warn', 'APP_URL is not https: GitHub will not deliver webhooks to it in production.'];

        return $out;
    }

    /** @return list<array{0: 'ok'|'warn'|'fail', 1: string}> */
    private function privateKey(array $c): array
    {
        $key = (string) ($c['private_key'] ?? '');
        $path = (string) ($c['private_key_path'] ?? '');
        $out = [];

        if ($key === '' && $path !== '') {
            if (! is_readable($path)) {
                return [['fail', 'GITHUB_APP_PRIVATE_KEY_PATH is not readable.']];
            }
            clearstatcache(true, $path);
            $perms = fileperms($path) & 0777;
            $out[] = ($perms & 0077) === 0 ? ['ok', 'Private key file is private to its owner.'] : ['warn', sprintf('Private key file mode is %04o: restrict it to 0600 or 0400.', $perms)];
            $key = (string) file_get_contents($path);
        }
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        $out[] = $key !== '' && openssl_pkey_get_private($key) !== false ? ['ok', 'Private key parses and can sign.'] : ['fail', 'No usable private key: set GITHUB_APP_PRIVATE_KEY (base64:) or GITHUB_APP_PRIVATE_KEY_PATH.'];

        return $out;
    }
}
