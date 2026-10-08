<?php

namespace App\Models\Hrms\Holiday;

use App\Enums\Hrms\HolidayType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Holiday/HRMS — one concrete date in a calendar.
 *
 * Recurring rows store the current year's occurrence in `date`; the
 * month/day is what recurs, expanded per year by the service. A recurring
 * row is never rewritten in place — expansion reads it, so the stored date
 * surviving edits is what keeps every year's occurrence stable.
 */
class Holiday extends Model
{
    protected $table = 'holidays';

    protected $fillable = [
        'calendar_id',
        'name',
        'date',
        'type',
        'is_recurring',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'calendar_id' => 'integer',
            'date' => 'date',
            'type' => HolidayType::class,
            'is_recurring' => 'boolean',
        ];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class, 'calendar_id');
    }
}
