<?php

namespace App\Models\Hrms\Payroll;

use App\Enums\Hrms\RevisionStatus;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payroll/HRMS — one CTC change walking toward an assignment.
 *
 * Below the approval threshold the revision approves itself (audited as
 * auto, the engine's own rule); above it, the chain decides and `apply()`
 * materialises. Either way the letter rides along as a document link, so
 * the number and its paper never part.
 */
class SalaryRevision extends Model
{
    protected $table = 'salary_revisions';

    protected $fillable = [
        'employee_id',
        'from_ctc',
        'to_ctc',
        'change_percent',
        'effective_from',
        'reason',
        'status',
        'approval_id',
        'approved_by_user_id',
        'approved_at',
        'letter_document_id',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'from_ctc' => 'decimal:2',
            'to_ctc' => 'decimal:2',
            'change_percent' => 'decimal:2',
            'effective_from' => 'date',
            'status' => RevisionStatus::class,
            'approval_id' => 'integer',
            'approved_by_user_id' => 'integer',
            'approved_at' => 'datetime',
            'letter_document_id' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'letter_document_id');
    }

    public function isOpen(): bool
    {
        return ! $this->status->isTerminal();
    }
}
