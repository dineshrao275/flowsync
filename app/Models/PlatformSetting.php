<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Central platform-wide settings (system DB). Boolean/numeric/string scalars
 * stored as strings; helpers coerce on read. Seed defaults in TenantSeeder.
 */
class PlatformSetting extends Model
{
    use CentralConnection;

    protected $fillable = ['key', 'value'];

    public static function value(string $key, mixed $default = null): mixed
    {
        $raw = static::query()->where('key', $key)->value('value');

        return $raw === null ? $default : $raw;
    }

    public static function bool(string $key, mixed $default = null): bool
    {
        return filter_var(static::value($key, $default), FILTER_VALIDATE_BOOL);
    }

    public static function set(string $key, mixed $value): static
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value === null ? null : (string) $value]
        );
    }
}
