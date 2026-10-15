<?php

namespace App\Models\Hrms\Asset;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Asset/HRMS — one catalogue row for the register.
 *
 * Tenant-built (no starters are seeded — every tenant's hardware differs).
 * A category with assets behind it refuses deletion in the service, with
 * the database's NO ACTION backstopping instead of cascading history away.
 */
class AssetCategory extends Model
{
    protected $table = 'asset_categories';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'default_condition',
        'is_active',
        'position',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
            'is_system' => 'boolean',
        ];
    }

    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'category_id');
    }
}
