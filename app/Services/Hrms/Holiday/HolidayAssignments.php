<?php

namespace App\Services\Hrms\Holiday;

use App\Enums\Hrms\HolidayType;
use App\Enums\Hrms\OptionalHolidayStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Holiday\EmployeeHolidayCalendar;
use App\Models\Hrms\Holiday\Holiday;
use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Models\Hrms\Holiday\HolidayOptionalHoliday;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Holiday/HRMS — who follows what, and their optional answers.
 *
 * The assignment half of holidays (reads live in `HolidayService` — the P5
 * split rule): following a calendar, leaving it, and answering restricted
 * days. Small on purpose — assignments are link rows, and link rows need
 * overlap checks and audit lines, not a second engine.
 */
class HolidayAssignments
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /**
     * Follow a calendar from a date, optionally to one.
     *
     * @throws ValidationException on an inactive calendar or an overlapping window
     */
    public function assign(Employee $employee, HolidayCalendar $calendar, Carbon|string $from, Carbon|string|null $to = null, ?User $actor = null): EmployeeHolidayCalendar
    {
        if (! $calendar->is_active) {
            throw ValidationException::withMessages(['calendar_id' => 'That calendar is not active.']);
        }

        $from = $from instanceof Carbon ? $from->toDateString() : (string) $from;
        $to = $to === null ? null : ($to instanceof Carbon ? $to->toDateString() : (string) $to);

        $overlap = EmployeeHolidayCalendar::query()
            ->where('employee_id', $employee->id)
            ->where('calendar_id', $calendar->id)
            ->whereDate('effective_from', '<=', $to ?? '9999-12-31')
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['form' => 'This calendar already covers part of that window.']);
        }

        $assignment = EmployeeHolidayCalendar::create([
            'employee_id' => $employee->id,
            'calendar_id' => $calendar->id,
            'effective_from' => $from,
            'effective_to' => $to,
        ]);

        $this->audit->log($assignment, 'holiday.assigned', null, [
            'employee_id' => $employee->id,
            'calendar_id' => $calendar->id,
        ], $actor);

        return $assignment->refresh();
    }

    public function unassign(EmployeeHolidayCalendar $assignment, ?User $actor = null): void
    {
        $snapshot = ['employee_id' => $assignment->employee_id, 'calendar_id' => $assignment->calendar_id];
        $assignment->delete();

        $this->audit->log($assignment, 'holiday.unassigned', $snapshot, null, $actor);
    }

    /**
     * Answer a restricted or optional holiday: taken (on a date) or
     * skipped. Public holidays need no declaration — the office is closed
     * either way, so declaring one 422s instead of recording a no-op.
     *
     * @throws ValidationException on a public holiday
     */
    public function declareOptional(
        Employee $employee,
        Holiday $holiday,
        OptionalHolidayStatus|string $status,
        Carbon|string|null $takenDate = null,
        ?string $note = null,
        ?User $actor = null,
    ): HolidayOptionalHoliday {
        $status = $status instanceof OptionalHolidayStatus ? $status : OptionalHolidayStatus::tryFrom((string) $status);

        if ($status === null) {
            throw ValidationException::withMessages(['status' => 'An answer is taken or skipped.']);
        }

        if ($holiday->type === HolidayType::Public) {
            throw ValidationException::withMessages(['holiday_id' => 'Public holidays need no declaration.']);
        }

        $answer = HolidayOptionalHoliday::updateOrCreate(
            ['employee_id' => $employee->id, 'holiday_id' => $holiday->id],
            [
                'status' => $status->value,
                'taken_date' => $takenDate === null ? null : ($takenDate instanceof Carbon ? $takenDate->toDateString() : (string) $takenDate),
                'note' => $note,
            ],
        );

        $this->audit->log($answer, 'holiday.optional_declared', null, [
            'employee_id' => $employee->id,
            'holiday_id' => $holiday->id,
            'status' => $status->value,
        ], $actor);

        return $answer->refresh();
    }
}
