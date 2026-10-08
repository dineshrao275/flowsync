<?php

namespace App\Models\Hrms\Statutory;

use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Statutory/HRMS — one employee's statutory identifiers.
 *
 * At most one row per person (unique), cascading with the employment record
 * so no orphan PII outlives its person. `bank_account_encrypted` is the
 * only value the database must never hold in cleartext (encrypted cast);
 * everything else is protected by masking on read, never by encryption —
 * and the full aadhaar number is never stored at all, only its last four.
 */
class StatutoryProfile extends Model
{
    protected $table = 'statutory_profiles';

    protected $fillable = [
        'employee_id',
        'pan',
        'aadhaar_last4',
        'uan',
        'esi_number',
        'pf_number',
        'pt_state',
        'lwf_registration',
        'bank_name',
        'bank_account_encrypted',
        'bank_ifsc',
        'tax_declaration',
        'declarations',
        'verified_at',
        'verified_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'lwf_registration' => 'boolean',
            'bank_account_encrypted' => 'encrypted',
            'tax_declaration' => 'array',
            'declarations' => 'array',
            'verified_at' => 'datetime',
            'verified_by_user_id' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
