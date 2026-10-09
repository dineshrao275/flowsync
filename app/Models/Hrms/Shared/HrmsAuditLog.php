<?php

namespace App\Models\Hrms\Shared;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Shared/HRMS — append-only record of a *change* to a business record.
 *
 * Write-only in practice: rows are never updated, and the table has no
 * `updated_at` at all so an accidental update is impossible. Created with
 * `const CREATED_AT = null` because Eloquent would otherwise demand
 * `updated_at` on every save.
 *
 * This is the durable audit trail. The `hrms` log channel is the operational
 * stream for correlation; neither substitutes for the other (D2.9).
 *
 * @property int $id
 * @property int|null $actor_user_id
 * @property int|null $actor_employee_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $action
 * @property array<string, mixed>|null $data
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 */
class HrmsAuditLog extends Model
{
    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $table = 'hrms_audit_logs';

    protected $fillable = [
        'actor_user_id',
        'actor_employee_id',
        'subject_type',
        'subject_id',
        'action',
        'data',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'actor_user_id' => 'integer',
            'actor_employee_id' => 'integer',
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @param Builder<HrmsAuditLog> $query */
    public function scopeForSubject(Builder $query, Model $subject): void
    {
        $query->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }
}
