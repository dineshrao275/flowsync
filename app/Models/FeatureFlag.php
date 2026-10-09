<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Central runtime flag (P8.7): a global switch + rollout %, refined by per-tenant overrides. */
class FeatureFlag extends Model
{
    use CentralConnection;

    protected $fillable = ['key', 'description', 'enabled', 'rollout_percent'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'rollout_percent' => 'integer'];
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(FlagOverride::class);
    }
}
