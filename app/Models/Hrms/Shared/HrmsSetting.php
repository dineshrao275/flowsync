<?php

namespace App\Models\Hrms\Shared;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared/HRMS — the per-tenant operational settings singleton.
 *
 * Always exactly one row, `id = 1`, seeded by the `000014` migration (and
 * re-affirmed by `TenantProvisioner::provisionHrmsDefaults()` in P1.10). The
 * tables always exist; whether HRMS is *usable* is a plan-entitlement question
 * decided by `TenantLimits::isHrmsEnabled()`, never by this row's presence.
 *
 * @property int $id
 * @property int $week_start
 * @property string $timezone
 * @property string|null $country
 * @property string|null $region
 * @property string $currency
 * @property int $fiscal_year_start_month
 * @property int $leave_year_start_month
 * @property array<string, mixed>|null $attendance
 * @property array<string, mixed>|null $remote_clock_in
 * @property array<string, mixed>|null $statutory
 * @property bool $mask_sensitive
 * @property int $data_retention_months
 */
class HrmsSetting extends Model
{
    public const SINGLETON_ID = 1;

    protected $table = 'hrms_settings';

    protected $fillable = [
        'week_start',
        'timezone',
        'country',
        'region',
        'currency',
        'fiscal_year_start_month',
        'leave_year_start_month',
        'attendance',
        'remote_clock_in',
        'statutory',
        'mask_sensitive',
        'data_retention_months',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'integer',
            'fiscal_year_start_month' => 'integer',
            'leave_year_start_month' => 'integer',
            'attendance' => 'array',
            'remote_clock_in' => 'array',
            'statutory' => 'array',
            'mask_sensitive' => 'boolean',
            'data_retention_months' => 'integer',
        ];
    }

    /**
     * The one and only settings row.
     *
     * A query rather than a cached property so a write followed by a read in
     * the same request sees the new value.
     */
    public static function current(): self
    {
        return static::query()->findOrFail(self::SINGLETON_ID);
    }

    public function isMasked(): bool
    {
        return $this->mask_sensitive;
    }

    /**
     * Read a nested setting, e.g. `setting('attendance.full_day_minutes')`.
     *
     * Dot-notation lookup on the decoded JSON sections, with a top-level
     * attribute fallback.
     *
     * Named `setting()` and reading via `getAttribute()` on purpose: Eloquent
     * proxies unknown model methods to the query builder, so both `value()` and
     * `get()` are already builder methods. A `setting()` calling `$this->get()`
     * would silently issue a fresh `SELECT` and return a Collection instead of
     * the JSON-decoded array.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->getAttribute($segments[0]);

        foreach (array_slice($segments, 1) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value ?? $default;
    }
}
