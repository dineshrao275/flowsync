<?php

namespace App\Models\Hrms\Statutory;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Statutory/HRMS — one jurisdiction's rulebook, owned by the tenant.
 *
 * Copied from a preset, then confirmed by a statutory expert — the engine
 * reads this row, never `config/hrms.php` (D2.9). `code` is the natural key
 * (`in-epf-2026`); country + region selects the row an employee falls under,
 * with a region-specific row beating a country-wide one.
 */
class StatutoryConfiguration extends Model
{
    protected $table = 'statutory_configurations';

    protected $fillable = [
        'country',
        'region',
        'name',
        'code',
        'is_active',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'config' => 'array',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
