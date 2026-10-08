<?php

namespace App\Models\Hrms\Performance;

use App\Enums\Hrms\OneOnOneStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Performance/HRMS — one scheduled conversation.
 *
 * Outside cycles on purpose: a 1:1 is a relationship, not a review period,
 * and tying it to a cycle would strand the history every half year.
 * Action items ride as JSON because they are the meeting's own checklist,
 * not records anything else reads.
 */
class OneOnOne extends Model
{
    protected $table = 'one_on_ones';

    protected $fillable = [
        'employee_id',
        'manager_employee_id',
        'scheduled_at',
        'duration_minutes',
        'agenda',
        'notes',
        'follow_up',
        'action_items',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'manager_employee_id' => 'integer',
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'action_items' => 'array',
            'status' => OneOnOneStatus::class,
            'created_by' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
