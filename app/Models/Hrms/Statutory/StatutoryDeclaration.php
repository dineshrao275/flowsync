<?php

namespace App\Models\Hrms\Statutory;

use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Statutory/HRMS — one exemption claim for one fiscal year.
 *
 * A declaration is the record; the proof file is its evidence (an orphaned
 * link when the file goes, never a deleted claim). Only `verified` rows
 * reduce tax — a submitted-but-unchecked claim is a promise, not a fact,
 * and the engine never prices promises.
 */
class StatutoryDeclaration extends Model
{
    protected $table = 'statutory_declarations';

    protected $fillable = [
        'employee_id',
        'fiscal_year',
        'section',
        'declared_amount',
        'proof_document_id',
        'status',
        'submitted_at',
        'verified_at',
        'verified_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'fiscal_year' => 'integer',
            'declared_amount' => 'decimal:2',
            'proof_document_id' => 'integer',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'verified_by_user_id' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function proof(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'proof_document_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
