<?php

namespace App\Models\Hrms\Holiday;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Holiday/HRMS — one named calendar, optionally per country/region.
 *
 * `is_default` marks the tenant fallback every employee reads unless
 * assigned otherwise. A single default is a service concern (P8.4), not a
 * partial index (the boolean-predicate trap in 0.6).
 */
class HolidayCalendar extends Model
{
    protected $table = 'holiday_calendars';

    protected $fillable = [
        'name',
        'slug',
        'country',
        'region',
        'description',
        'is_default',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return HasMany<Holiday, $this> */
    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class, 'calendar_id')->orderBy('date');
    }

    /** @param Builder<HolidayCalendar> $query */
    public function scopeDefault(Builder $query): void
    {
        $query->where('is_default', true);
    }

    /** @param Builder<HolidayCalendar> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
