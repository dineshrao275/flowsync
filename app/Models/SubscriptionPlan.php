<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class SubscriptionPlan extends Model
{
    use CentralConnection;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'is_default',
        'billing_cycle',
        'price_cents',
        'currency',
        'trial_duration_days',
        'limits',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'price_cents' => 'integer',
            'trial_duration_days' => 'integer',
            'limits' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class, 'to_plan_id');
    }

    /**
     * A single numeric/boolean limit value; null when the plan sets no such limit.
     */
    public function limit(string $key): mixed
    {
        return data_get($this->limits, $key);
    }

    public function limitsFor(string $key): mixed
    {
        return $this->limit($key);
    }

    public function hasModule(string $module): bool
    {
        return in_array($module, $this->limit('modules') ?? [], true);
    }

    /**
     * End of the billing period: one cycle (monthly/annual) after the anchor, or
     * `now` when no anchor is given.
     */
    public function periodEnd(?Carbon $from = null): Carbon
    {
        $months = $this->billing_cycle === 'annual' ? 12 : 1;

        return $from?->copy()->addMonths($months) ?? now()->addMonths($months);
    }
}
