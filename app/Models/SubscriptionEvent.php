<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionEvent extends Model
{
    use CentralConnection;

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'type',
        'from_plan_id',
        'to_plan_id',
        'data',
        'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'from_plan_id');
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'to_plan_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'actor_id');
    }
}
