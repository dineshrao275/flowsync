<?php

namespace App\Support;

/**
 * Normalizes the stored color scheme (`light` | `dark` | `system`).
 *
 * The scheme lives next to the theme colors in user_settings.settings['theme']
 * but is deliberately NOT part of `config('theme.defaults')`, because that
 * array's keys drive the hex validation in ThemeUpdateRequest.
 */
class ThemeMode
{
    /**
     * The configured scheme when the stored value is missing or unknown.
     */
    public static function resolve(mixed $mode = null): string
    {
        $modes = config('theme.modes');

        return is_string($mode) && in_array($mode, $modes, true)
            ? $mode
            : config('theme.default_mode');
    }

    public static function isValid(mixed $mode): bool
    {
        return is_string($mode) && in_array($mode, config('theme.modes'), true);
    }
}
