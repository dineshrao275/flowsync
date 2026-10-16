<?php

namespace App\Http\Controllers;

use App\Http\Requests\SiteSettingsUpdateRequest;
use App\Models\PlatformSetting;
use App\Services\PlatformAudit;
use App\Support\SiteConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

/**
 * CMS admin (super admin). Global public-site settings (brand, nav, footer,
 * social, analytics, announcement, custom code) consumed by the site layout.
 * Stored as `website.*` platform settings; see App\Support\SiteConfig.
 */
class SiteSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = SiteConfig::all();
        unset($settings['logo']);

        return response()->json(['settings' => $settings]);
    }

    public function update(SiteSettingsUpdateRequest $request): JsonResponse
    {
        $data = $request->validated();

        $before = SiteConfig::all();
        unset($before['logo']);

        foreach (array_keys(SiteConfig::defaults()) as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            PlatformSetting::set(
                "website.{$key}",
                in_array($key, SiteConfig::JSON_KEYS, true) ? json_encode(array_values($value ?? [])) : $value
            );
        }

        $after = SiteConfig::all();
        unset($after['logo']);

        $changed = collect(array_keys(SiteConfig::defaults()))
            ->filter(fn (string $key) => ($before[$key] ?? null) != ($after[$key] ?? null))
            ->values()->all();

        if ($changed) {
            app(PlatformAudit::class)->diff(
                $request,
                'cms.site_settings_updated',
                'platform_settings',
                null,
                Arr::only($before, $changed),
                Arr::only($after, $changed),
                ['keys' => $changed],
            );
        }

        return response()->json(['settings' => $after, 'message' => __('Site settings saved.')]);
    }
}
