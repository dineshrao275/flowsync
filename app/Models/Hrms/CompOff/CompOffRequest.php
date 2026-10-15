<?php

namespace App\Models\Hrms\CompOff;

use App\Enums\Hrms\CompOffRequestStatus;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompOff/HRMS — redeeming banked comp-off time.
 *
 * Submitted first, never draft: filing opens the chain immediately, like
 * the rest of the approval-backed asks. Minutes, not days: a redemption
 * debits the same standard-day minutes a credit banks.
 */
class CompOffRequest extends Model
{
    use SoftDeletes;

    protected $table = 'comp_off_requests';

    protected $fillable = [
        'employee_id',
        'from_date',
        'to_date',
        'total_minutes',
        'reason',
        'status',
        'approval_id',
        'decided_at',
        'decided_by_user_id',
        'document_id',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'from_date' => 'date',
            'to_date' => 'date',
            'total_minutes' => 'integer',
            'status' => CompOffRequestStatus::class,
            'approval_id' => 'integer',
            'decided_at' => 'datetime',
            'decided_by_user_id' => 'integer',
            'document_id' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<CompOffRequestDay, $this> */
    public function days(): HasMany
    {
        return $this->hasMany(CompOffRequestDay::class, 'comp_off_request_id')->orderBy('date');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'document_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function isOpen(): bool
    {
        return ! $this->status->isTerminal();
    }
}
