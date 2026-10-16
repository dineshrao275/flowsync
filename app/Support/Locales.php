<?php

namespace App\Support;

/**
 * Pure helpers over `config/locales.php` (i18n Phase 0). No I/O, no session —
 * the resolution order lives in `SetRequestLocale`, which is the single
 * caller that combines these with the request state.
 */
class Locales
{
    /**
     * @return array<string, array{name: string, native: string, dir: string, intl: string, complete: bool}>
     */
    public static function supported(): array
    {
        return config('locales.supported', ['en' => [
            'name' => 'English', 'native' => 'English', 'dir' => 'ltr', 'intl' => 'en-US', 'complete' => true,
        ]]);
    }

    /**
     * @return array<string, array{name: string, native: string, dir: string, intl: string, complete: bool}>
     */
    public static function completed(): array
    {
        return array_filter(static::supported(), fn (array $meta): bool => $meta['complete'] ?? false);
    }

    public static function default(): string
    {
        $default = config('locales.default', config('app.locale', 'en'));

        return static::isSupported($default) ? $default : 'en';
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, static::supported());
    }

    public static function isComplete(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, static::completed());
    }

    /**
     * Normalize free-form input (`es-MX`, `en_US`, ` ES `) to a supported key,
     * matching on the primary subtag so regional variants degrade to the
     * shipped dictionary instead of the default. Null when nothing matches.
     */
    public static function normalize(?string $locale): ?string
    {
        if ($locale === null || trim($locale) === '') {
            return null;
        }

        $tag = strtolower(str_replace('_', '-', trim($locale)));

        if (static::isSupported($tag)) {
            return $tag;
        }

        $primary = explode('-', $tag)[0];

        return static::isSupported($primary) ? $primary : null;
    }

    /**
     * First supported match from an `Accept-Language` header, honoring
     * q-values. Null when the header names nothing shipped.
     */
    public static function negotiate(?string $header): ?string
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $ranges = [];

        foreach (explode(',', $header) as $part) {
            $segments = explode(';', trim($part));
            $tag = trim($segments[0]);

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $weight = 1.0;

            foreach (array_slice($segments, 1) as $param) {
                if (preg_match('/^q=([0-9.]+)$/', trim($param), $match) === 1) {
                    $weight = (float) $match[1];
                }
            }

            $ranges[] = ['tag' => $tag, 'weight' => $weight];
        }

        usort($ranges, fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        foreach ($ranges as $range) {
            $match = static::normalize($range['tag']);

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }
}
