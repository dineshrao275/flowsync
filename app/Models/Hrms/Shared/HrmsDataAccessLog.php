<?php

namespace App\Models\Hrms\Shared;

use App\Enums\Hrms\DataAccessAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Shared/HRMS — append-only record of a *read* of sensitive data.
 *
 * Separate from {@see HrmsAuditLog} because "someone opened Jane's payslip" is
 * not a change to anything; recording it in the change ledger would either
 * pollute it or invite a fake "viewed" edit. Like the audit ledger it has no
 * `updated_at`.
 *
 * @property int $id
 * @property int|null $actor_user_id
 * @property string $model
 * @property int $record_id
 * @property DataAccessAction $action
 * @property array<int, string>|null $fields
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 */
class HrmsDataAccessLog extends Model
{
    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $table = 'hrms_data_access_logs';

    protected $fillable = [
        'actor_user_id',
        'model',
        'record_id',
        'action',
        'fields',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'record_id' => 'integer',
            'actor_user_id' => 'integer',
            'action' => DataAccessAction::class,
            'fields' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @param Builder<HrmsDataAccessLog> $query */
    public function scopeForModel(Builder $query, string $model): void
    {
        $query->where('model', $model);
    }

    /** @param Builder<HrmsDataAccessLog> $query */
    public function scopeForActor(Builder $query, int $userId): void
    {
        $query->where('actor_user_id', $userId);
    }
}
