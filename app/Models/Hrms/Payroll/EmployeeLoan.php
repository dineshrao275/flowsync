<?php

declare(strict_types=1);

namespace App\Models\Hrms\Payroll;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\Approval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeLoan extends Model
{
    public const KINDS = ['loan', 'advance'];

    protected $table = 'employee_loans';

    protected $fillable = [
        'employee_id', 'kind', 'principal', 'installments', 'installment_amount', 'first_year', 'first_month', 'balance',
        'reason', 'status', 'approval_id', 'requested_by_user_id', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'principal' => 'decimal:2', 'installment_amount' => 'decimal:2', 'balance' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function schedule(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('number');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
