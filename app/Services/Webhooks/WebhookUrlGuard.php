<?php

namespace App\Services\Webhooks;

use Illuminate\Validation\ValidationException;

/**
 * Keeps a webhook URL from being a way into our own network (SSRF): https only (http is
 * allowed for local development), no credentials in the URL, a normal port, and a host
 * that does not resolve to a loopback, private, link-local or reserved address. Checked
 * when an endpoint is saved AND again at delivery time, because DNS can change.
 */
class WebhookUrlGuard
{
    private const PORTS = [80, 443, 8080, 8443];

    /** @throws ValidationException */
    public function assertSafe(string $url, string $field = 'url'): void
    {
        $parts = parse_url($url);
        $fail = fn (string $why) => throw ValidationException::withMessages([$field => $why]);

        if (! is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            $fail('Enter a full URL such as https://example.com/hooks.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            $fail('The URL must not contain credentials.');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && ! ($scheme === 'http' && config('webhooks.allow_http'))) {
            $fail('The URL must use https.');
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, self::PORTS, true)) {
            $fail('Use port 443, 8443, 80 or 8080.');
        }

        foreach ($this->addresses($parts['host']) as $ip) {
            if (! $this->isPublic($ip) && ! config('webhooks.allow_private')) {
                $fail('That address is not reachable from the internet (private, loopback or reserved).');
            }
        }
    }

    /** @return list<string> */
    private function addresses(string $host): array
    {
        $host = trim($host, '[]');
        // Tests swap DNS for a fixed answer.
        if (is_callable($resolver = config('webhooks.resolver'))) {
            return $resolver($host);
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $v4 = gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        $all = [...$v4, ...$v6];
        if ($all === []) {
            throw ValidationException::withMessages(['url' => 'That host name does not resolve.']);
        }

        return $all;
    }

    private function isPublic(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
