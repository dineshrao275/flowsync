<?php

namespace App\Models\Hrms\Document;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSignature extends Model
{
    protected $table = 'document_signatures';

    protected $fillable = [
        'document_id', 'employee_id', 'requested_by_user_id', 'status', 'verification_code', 'message', 'typed_name',
        'document_sha256', 'receipt_hash', 'signed_ip', 'signed_user_agent', 'signed_at', 'decline_reason',
    ];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'document_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
