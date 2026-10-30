<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * The one answer to "what is this product called?". The Super Admin edits it in Settings (`app_name` platform
 * setting); every surface — SPA, page titles, emails, notifications, public site, exports — reads it from here or from
 * `config('app.name')`, which `apply()` points at the same value on every request/job. `config('app.name')` (env
 * `APP_NAME`) is only the fallback before the setting exists.
 */
class AppName
{
    public const CACHE_KEY = 'platform.app_name';

    public static function get(): string
    {
        return (string) Cache::remember(self::CACHE_KEY, 300, function (): string {
            try {
                $value = trim((string) PlatformSetting::value('app_name', ''));
            } catch (\Throwable) {
                $value = ''; // central DB unavailable (early boot, migrations): fall back to config
            }

            return $value !== '' ? $value : (string) config('app.name', 'FlowSync');
        });
    }

    /** Make `config('app.name')` (mail footers, notifications, framework defaults) follow the setting. */
    public static function apply(): void
    {
        config(['app.name' => self::get()]);

        if (config('mail.from.follows_app_name', true)) {
            config(['mail.from.name' => config('app.name')]);
        }
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
