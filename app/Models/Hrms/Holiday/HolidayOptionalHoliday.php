<?php

namespace App\Models\Hrms\Holiday;

use App\Enums\Hrms\OptionalHolidayStatus;
use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Holiday/HRMS — one employee's answer to a restricted holiday.
 *
 * Single-valued by construction (the unique pair): taken on a date, or
 * skipped. A taken answer is what turns a working restricted day into
 * time off; a skipped one is the record that it was considered.
 */
class HolidayOptionalHoliday extends Model
{
    protected $table = 'holiday_optional_holidays';

    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'holiday_id',
        'status',
        'taken_date',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'holiday_id' => 'integer',
            'status' => OptionalHolidayStatus::class,
            'taken_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class, 'holiday_id');
    }
}
