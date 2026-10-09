<?php

namespace App\Support;

/**
 * Builds the Content-Security-Policy value (P8.5).
 *
 * The SPA ships an inline theme bootstrap script and Tailwind/inline styles, so
 * `'unsafe-inline'` stays on script/style until nonces land; everything else is
 * pinned to self plus the integrations the app really talks to: Stripe (js,
 * hosted frames, API), Reverb websockets, Google Fonts, and - only while the Vite
 * dev server is running (`public/hot`) - that dev origin.
 */
class ContentSecurityPolicy
{
    public function build(): string
    {
        $self = "'self'";
        $ws = $this->reverbOrigins();
        $dev = $this->devOrigins();

        $directives = [
            'default-src' => [$self],
            'base-uri' => [$self],
            'object-src' => ["'none'"],
            'frame-ancestors' => [$self],
            'form-action' => [$self, 'https://checkout.stripe.com'],
            'script-src' => [$self, "'unsafe-inline'", 'https://js.stripe.com', ...$dev],
            'style-src' => [$self, "'unsafe-inline'", 'https://fonts.googleapis.com', ...$dev],
            'font-src' => [$self, 'data:', 'https://fonts.gstatic.com'],
            'img-src' => [$self, 'data:', 'blob:', 'https:'],
            'connect-src' => [$self, 'https://api.stripe.com', ...$ws, ...$dev],
            'frame-src' => [$self, 'https://js.stripe.com', 'https://hooks.stripe.com', 'https://checkout.stripe.com'],
        ];

        foreach ((array) config('security.csp.extra', []) as $name => $extra) {
            if (isset($directives[$name])) {
                $directives[$name] = array_values(array_unique([...$directives[$name], ...$extra]));
            }
        }

        $parts = [];
        foreach ($directives as $name => $sources) {
            $parts[] = $name.' '.implode(' ', $sources);
        }
        if ($uri = config('security.csp.report_uri')) {
            $parts[] = 'report-uri '.$uri;
        }

        return implode('; ', $parts);
    }

    /** @return list<string> */
    private function reverbOrigins(): array
    {
        $host = config('reverb.apps.apps.0.options.host') ?: env('REVERB_HOST');
        $port = config('reverb.apps.apps.0.options.port') ?: env('REVERB_PORT');
        if (! $host) {
            return [];
        }
        $hostPort = $host.($port ? ':'.$port : '');

        return ['ws://'.$hostPort, 'wss://'.$hostPort];
    }

    /** @return list<string> */
    private function devOrigins(): array
    {
        $hot = public_path('hot');
        if (! is_file($hot)) {
            return [];
        }
        $url = rtrim(trim((string) file_get_contents($hot)), '/');
        $port = parse_url($url, PHP_URL_PORT);
        $origin = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).($port ? ':'.$port : '');

        return [$origin, str_replace(['http://', 'https://'], 'ws://', $origin)];
    }
}
