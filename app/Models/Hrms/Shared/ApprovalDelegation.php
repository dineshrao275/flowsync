<?php

namespace App\Models\Hrms\Shared;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Shared/HRMS — "while I am away, my approvals are yours" (P2.4).
 *
 * @property int $id
 * @property int $from_user_id
 * @property int $to_user_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property list<string>|null $domains null = every domain
 * @property string|null $reason
 * @property Carbon|null $revoked_at
 * @property int|null $created_by_user_id
 */
class ApprovalDelegation extends Model
{
    protected $table = 'approval_delegations';

    protected $fillable = [
        'from_user_id',
        'to_user_id',
        'starts_at',
        'ends_at',
        'domains',
        'reason',
        'revoked_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
            'domains' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /** @param Builder<ApprovalDelegation> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }

    public function coversDomain(?string $domain): bool
    {
        return $this->domains === null || $this->domains === [] || ($domain !== null && in_array($domain, $this->domains, true));
    }
}
