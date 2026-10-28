<?php

declare(strict_types=1);

namespace App\Models\Hrms\Employee;

use App\Models\Hrms\Shared\Approval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeChange extends Model
{
    public const TYPES = ['transfer', 'promotion', 'confirmation'];

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const APPLIED = 'applied';

    protected $table = 'employee_changes';

    protected $fillable = [
        'employee_id', 'type', 'effective_date', 'changes', 'reason', 'status', 'approval_id', 'requested_by_user_id',
        'decided_at', 'decided_by_user_id', 'decision_note', 'applied_at',
    ];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'changes' => 'array', 'decided_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::PENDING;
    }
}
