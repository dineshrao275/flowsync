<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DetectsPlatformUsers;
use App\Http\Requests\ThemeUpdateRequest;
use App\Models\PlatformSetting;
use App\Models\UserSettings;
use App\Support\ThemeMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThemeController extends Controller
{
    use DetectsPlatformUsers;

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'theme' => $this->themeFor($request),
        ]);
    }

    public function update(ThemeUpdateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if ($this->isPlatformSuperAdmin($request)) {
            // `user_settings` is a tenant table, so a platform super admin's
            // theme lives in the central key-value settings instead, keyed per
            // super admin so accounts do not share a scheme.
            $theme = $this->withMode($validated, $this->stored($request));
            PlatformSetting::set($this->platformKey($request), json_encode($theme));

            return response()->json([
                'message' => 'Theme saved.',
                'theme' => $theme,
            ]);
        }

        $settings = UserSettings::firstOrCreate(['user_id' => $request->user()->id]);

        $theme = $this->withMode($validated, $settings->settings['theme'] ?? []);

        $settings->update([
            'settings' => array_merge($settings->settings ?? [], ['theme' => $theme]),
        ]);

        return response()->json([
            'message' => 'Theme saved.',
            'theme' => $theme,
        ]);
    }

    /**
     * Validated colors plus the requested scheme. An omitted `mode` keeps the
     * stored one, so a client that only submits colors never resets it.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function withMode(array $validated, array $stored): array
    {
        $storedMode = $stored['mode'] ?? null;

        return array_merge($validated, [
            'mode' => $validated['mode']
                ?? (ThemeMode::isValid($storedMode) ? $storedMode : config('theme.default_mode')),
        ]);
    }

    /**
     * Colors merged over the defaults plus the caller's color scheme.
     *
     * @return array<string, string>
     */
    private function themeFor(Request $request): array
    {
        $stored = $this->stored($request);

        return array_merge(
            config('theme.defaults'),
            array_intersect_key($stored, config('theme.defaults')),
            ['mode' => ThemeMode::resolve($stored['mode'] ?? null)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(Request $request): array
    {
        if ($this->isPlatformSuperAdmin($request)) {
            $raw = PlatformSetting::value($this->platformKey($request));
            $decoded = is_string($raw) ? json_decode($raw, true) : null;

            return is_array($decoded) ? $decoded : [];
        }

        return $request->user()->settings?->settings['theme'] ?? [];
    }

    private function platformKey(Request $request): string
    {
        return 'theme.user.'.$request->user()->id;
    }
}
