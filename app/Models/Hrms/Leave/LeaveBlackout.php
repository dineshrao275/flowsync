<?php

namespace App\Models\Hrms\Leave;

use App\Models\Hrms\Org\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Leave/HRMS — a date range in which leave cannot be asked for.
 *
 * Null `leave_type_id` / `department_id` mean "not narrowed": a blackout with
 * both null blocks everyone, for every type.
 *
 * @property int $id
 * @property string $name
 * @property Carbon $from_date
 * @property Carbon $to_date
 * @property int|null $leave_type_id
 * @property int|null $department_id
 * @property string|null $reason
 * @property bool $is_active
 * @property int|null $created_by
 */
class LeaveBlackout extends Model
{
    protected $table = 'leave_blackouts';

    protected $fillable = ['name', 'from_date', 'to_date', 'leave_type_id', 'department_id', 'reason', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'leave_type_id' => 'integer',
            'department_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
