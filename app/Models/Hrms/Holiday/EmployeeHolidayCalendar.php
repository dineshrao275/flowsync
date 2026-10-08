<?php

namespace App\Models\Hrms\Holiday;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Holiday/HRMS — who follows which calendar, when.
 *
 * A link row with its own validity window and no timestamps: the window is
 * the record, not its bookkeeping (the leave-pivot precedent).
 */
class EmployeeHolidayCalendar extends Model
{
    protected $table = 'employee_holiday_calendars';

    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'calendar_id',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'calendar_id' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class, 'calendar_id');
    }
}
