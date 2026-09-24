<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use CentralConnection;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_DEACTIVATED = 'deactivated';

    public const STATUS_PROVISIONING_FAILED = 'provisioning_failed';

    public const PROVISIONING_PENDING = 'pending';

    public const PROVISIONING_DB_CREATED = 'db_created';

    public const PROVISIONING_MIGRATED = 'migrated';

    public const PROVISIONING_SEEDED = 'seeded';

    public const PROVISIONING_PROVISIONED = 'provisioned';

    public const PROVISIONING_FAILED = 'failed';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'provisioning_status' => self::PROVISIONING_PROVISIONED,
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'status',
        'provisioning_status',
        'provisioning_error',
        'provisioned_at',
        'db_name',
        'db_host',
        'db_port',
        'db_user',
        'db_password',
        'subscription_id',
        'billing_email',
        'contact_name',
        'contact_email',
        'trial_ends_at',
        'limits_override',
        'features_override',
        'onboarding_meta',
        'settings',
        'legal_name',
        'registration_number',
        'tax_id',
        'country',
        'street',
        'city',
        'state',
        'postal_code',
        'website',
        'industry',
        'company_size',
        'contact_phone',
        'billing_address',
        'billing_currency',
        'timezone',
        'locale',
        'brand_domain',
        'brand_logo_url',
        'brand_primary_color',
    ];

    protected $hidden = [
        'db_password',
    ];

    protected function casts(): array
    {
        return [
            'db_user' => 'encrypted',
            'db_password' => 'encrypted',
            'provisioned_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'limits_override' => 'array',
            'features_override' => 'array',
            'onboarding_meta' => 'array',
            'settings' => 'array',
        ];
    }

    public function impersonationLogs(): HasMany
    {
        return $this->hasMany(ImpersonationLog::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscriptionEvents(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class);
    }

    public function isTrial(): bool
    {
        return $this->status === self::STATUS_TRIAL;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Tenant is provisioned and in a state that serves tenant traffic.
     */
    public function isServiceable(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIAL], true);
    }

    public function isProvisioned(): bool
    {
        return $this->provisioning_status === self::PROVISIONING_PROVISIONED;
    }

    public function isDeactivated(): bool
    {
        return $this->status === self::STATUS_DEACTIVATED;
    }
}
