<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;
use App\Models\PlatformSetting;

/**
 * Global public-site configuration, stored as `website.*` platform settings
 * and edited on the Website settings admin page. One `whereIn` query per
 * render (no static memo — memoized config goes stale across tests and
 * long-lived workers, the TenantLimits lesson).
 */
final class SiteConfig
{
    /** Keys holding JSON; decoded on read, encoded on write. */
    public const JSON_KEYS = ['nav', 'footer_columns', 'social'];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'tagline' => 'Project management in motion — boards, time tracking, and HR in one calm workspace.',
            'logo_media_id' => null,
            'nav' => [
                ['label' => 'Home', 'href' => '/'],
                ['label' => 'Privacy', 'href' => '/privacy'],
                ['label' => 'Terms', 'href' => '/terms'],
            ],
            'footer_columns' => [
                [
                    'heading' => 'Product',
                    'links' => [
                        ['label' => 'Home', 'href' => '/'],
                        ['label' => 'Start free', 'href' => '/app/register'],
                        ['label' => 'Log in', 'href' => '/app/login'],
                    ],
                ],
                [
                    'heading' => 'Resources',
                    'links' => [['label' => 'Sitemap', 'href' => '/sitemap.xml']],
                ],
                [
                    'heading' => 'Legal',
                    'links' => [
                        ['label' => 'Privacy', 'href' => '/privacy'],
                        ['label' => 'Terms', 'href' => '/terms'],
                    ],
                ],
            ],
            'social' => [],
            'ga_measurement_id' => null,
            'gsc_verification' => null,
            'contact_email' => null,
            'sales_email' => null,
            'announcement' => null,
            'announcement_href' => null,
            'custom_head' => null,
            'custom_body' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = PlatformSetting::whereIn('key', array_map(
            fn (string $key) => "website.{$key}", array_keys(self::defaults())
        ))->pluck('value', 'key');

        $config = [];
        foreach (self::defaults() as $key => $default) {
            $raw = $stored->get("website.{$key}");

            if ($raw === null) {
                $config[$key] = $default;
            } elseif (in_array($key, self::JSON_KEYS, true)) {
                $config[$key] = json_decode($raw, true) ?? $default;
            } else {
                $config[$key] = $raw !== '' ? $raw : $default;
            }
        }

        $config['logo'] = $config['logo_media_id']
            ? Media::find($config['logo_media_id'])
            : null;

        return $config;
    }
}
