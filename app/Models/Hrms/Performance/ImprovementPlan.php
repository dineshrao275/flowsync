<?php

namespace App\Models\Hrms\Performance;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImprovementPlan extends Model
{
    public const ACTIVE = 'active';

    public const OUTCOMES = ['completed', 'unsuccessful', 'cancelled'];

    protected $table = 'improvement_plans';

    protected $fillable = ['employee_id', 'created_by_user_id', 'reason', 'start_date', 'end_date', 'status', 'milestones', 'outcome', 'closed_at'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'milestones' => 'array', 'closed_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
