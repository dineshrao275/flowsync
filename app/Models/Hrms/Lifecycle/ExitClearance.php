<?php

namespace App\Models\Hrms\Lifecycle;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lifecycle/HRMS — the sign-off on one exit.
 *
 * One row per case, recomputed by every `summary()`: the counters are a
 * photograph of what is still outstanding, not a ledger anyone appends to.
 * `blocked_reasons` is the human-readable twin of the counters — it is what
 * the 422 on a premature `clear()` carries, and what the detail screen makes
 * unmissable.
 *
 * `pending_expense_amount` is minor units (paise/cents), never a float:
 * floats round, and a clearance that rounds is a clearance that lies by a
 * paisa.
 *
 * @property int $id
 * @property int $case_id
 * @property int $pending_assets_count
 * @property int $pending_leave_encashment_days
 * @property int $pending_expense_amount
 * @property int $pending_documents_count
 * @property bool $dues_settled
 * @property int|null $cleared_by_user_id
 * @property Carbon|null $cleared_at
 * @property array<int, string>|null $blocked_reasons
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OffboardingCase $case
 */
class ExitClearance extends Model
{
    protected $table = 'exit_clearances';

    protected $fillable = [
        'case_id',
        'pending_assets_count',
        'pending_leave_encashment_days',
        'pending_expense_amount',
        'pending_documents_count',
        'dues_settled',
        'cleared_by_user_id',
        'cleared_at',
        'blocked_reasons',
    ];

    protected function casts(): array
    {
        return [
            'case_id' => 'integer',
            'pending_assets_count' => 'integer',
            'pending_leave_encashment_days' => 'integer',
            'pending_expense_amount' => 'integer',
            'pending_documents_count' => 'integer',
            'dues_settled' => 'boolean',
            'cleared_by_user_id' => 'integer',
            'cleared_at' => 'datetime',
            'blocked_reasons' => 'array',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(OffboardingCase::class, 'case_id');
    }

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by_user_id');
    }

    /**
     * Whether anything still stands between this exit and its sign-off.
     * Money, assets, leave days and files are four different questions, and
     * collapsing them into one boolean would lose which one is blocking.
     */
    public function isBlocked(): bool
    {
        return $this->pending_assets_count > 0
            || $this->pending_leave_encashment_days > 0
            || $this->pending_expense_amount > 0
            || $this->pending_documents_count > 0;
    }
}
