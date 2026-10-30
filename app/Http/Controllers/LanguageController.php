<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DetectsPlatformUsers;
use App\Http\Middleware\SetRequestLocale;
use App\Http\Requests\LanguageUpdateRequest;
use App\Models\PlatformSetting;
use App\Models\UserSettings;
use App\Support\Locales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LanguageController extends Controller
{
    use DetectsPlatformUsers;

    /**
     * The language catalog for switchers (public: the login page and the
     * marketing header render before any session exists). Only completed
     * dictionaries are offered.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'default' => Locales::default(),
            'locales' => Locales::completed(),
        ]);
    }

    /**
     * The caller's own language preference (i18n Phase 0). Self-scoped like
     * the notifications family — no permission gate, no module gate: every
     * account, including one whose plan hides everything else, may read the
     * product in their language.
     */
    public function update(LanguageUpdateRequest $request): JsonResponse
    {
        $locale = $request->validated()['locale'];

        if ($this->isPlatformSuperAdmin($request)) {
            // `user_settings` is a tenant table: a platform super admin has
            // no tenant database, so the preference lives in the central
            // key-value settings (the ThemeController precedent).
            PlatformSetting::set($this->platformKey($request), $locale);
        } else {
            $settings = UserSettings::firstOrCreate(['user_id' => $request->user()->id]);
            $settings->update([
                'settings' => array_merge($settings->settings ?? [], ['locale' => $locale]),
            ]);
        }

        if ($request->hasSession()) {
            $request->session()->put(SetRequestLocale::SESSION_KEY, $locale);
        }

        return response()->json([
            'message' => __('Language saved.'),
            'locale' => $locale,
        ]);
    }

    private function platformKey(Request $request): string
    {
        return 'locale.user.'.$request->user()->id;
    }
}
