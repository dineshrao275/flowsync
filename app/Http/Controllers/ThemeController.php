<?php

namespace App\Http\Controllers;

use App\Models\UserSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThemeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'theme' => $this->themeFor($request->user()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $defaults = config('theme.defaults');

        $validated = $request->validate([
            ...array_fill_keys(array_keys($defaults), [
                'required', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/',
            ]),
        ]);

        $settings = UserSettings::firstOrCreate(['user_id' => $request->user()->id]);

        $merged = array_merge($settings->settings ?? [], ['theme' => $validated]);
        $settings->update(['settings' => $merged]);

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
