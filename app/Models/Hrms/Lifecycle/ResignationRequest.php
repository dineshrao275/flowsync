<?php

declare(strict_types=1);

namespace App\Models\Hrms\Lifecycle;

use App\Enums\Hrms\ResignationStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\Approval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResignationRequest extends Model
{
    protected $table = 'resignation_requests';

    protected $fillable = [
        'employee_id', 'requested_last_day', 'notice_period_days', 'reason', 'status',
        'approval_id', 'decided_at', 'decided_by_user_id', 'decision_note', 'offboarding_case_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_last_day' => 'date',
            'status' => ResignationStatus::class,
            'decided_at' => 'datetime',
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
}
