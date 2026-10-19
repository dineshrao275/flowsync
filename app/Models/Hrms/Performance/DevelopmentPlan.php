<?php

namespace App\Models\Hrms\Performance;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevelopmentPlan extends Model
{
    protected $table = 'development_plans';

    protected $fillable = ['employee_id', 'created_by_user_id', 'title', 'focus', 'start_date', 'end_date', 'status', 'actions', 'closed_at'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'actions' => 'array', 'closed_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'active';
    }
}
