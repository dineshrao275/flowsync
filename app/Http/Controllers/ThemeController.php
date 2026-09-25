<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DetectsPlatformUsers;
use App\Http\Requests\ThemeUpdateRequest;
use App\Models\UserSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThemeController extends Controller
{
    use DetectsPlatformUsers;

    public function show(Request $request): JsonResponse
    {
        if ($this->isPlatformSuperAdmin($request)) {
            return response()->json(['theme' => config('theme.defaults')]);
        }

        return response()->json([
            'theme' => $this->themeFor($request->user()),
        ]);
    }

    public function update(ThemeUpdateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // `user_settings` is a tenant table, so a platform super admin's theme
        // is not persisted (AuthController::payload sends them the defaults).
        if (! $this->isPlatformSuperAdmin($request)) {
            $settings = UserSettings::firstOrCreate(['user_id' => $request->user()->id]);

            $merged = array_merge($settings->settings ?? [], ['theme' => $validated]);
            $settings->update(['settings' => $merged]);
        }

        return response()->json([
            'message' => 'Theme saved.',
            'theme' => $validated,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function themeFor(object $user): array
    {
        $theme = $user->settings?->settings['theme'] ?? [];

        return array_merge(config('theme.defaults'), array_intersect_key($theme, config('theme.defaults')));
    }
}
