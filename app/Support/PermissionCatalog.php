<?php

namespace App\Support;

/** The set of permission slugs the platform defines (config/permissions.php, scope variants included). */
final class PermissionCatalog
{
    /** @var array<string, true>|null */
    private static ?array $slugs = null;

    public static function has(string $slug): bool
    {
        return isset(self::set()[$slug]);
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::set());
    }

    public static function flush(): void
    {
        self::$slugs = null;
    }

    /** @return array<string, true> */
    private static function set(): array
    {
        return self::$slugs ??= collect(config('permissions.permissions', []))
            ->pluck('slug')->mapWithKeys(fn (string $s): array => [$s => true])->all();
    }
}
