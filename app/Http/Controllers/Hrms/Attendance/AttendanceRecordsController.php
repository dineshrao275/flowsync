<?php

namespace App\Http\Controllers\Hrms\Attendance;

use App\Enums\Hrms\DataAccessAction;
use App\Http\Controllers\Controller;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\Attendance\DayPresenter;
use App\Services\Hrms\AttendanceService;
use App\Services\HrmsAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attendance/HRMS — reading attendance back out over HTTP.
 *
 * The read half to {@see AttendanceController}'s writes: the month grid,
 * the clock widget's today, and the CSV export. All three answer "whose
 * curve" through the same `AttendanceDayPolicy::view` (self-service
 * included), and all three are pure reads — nothing here materializes a
 * row; the rollup owns that.
 */
class AttendanceRecordsController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly DayPresenter $presenter,
        private readonly HrmsAuditLogger $audit,
    ) {}

    public function month(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $employee = $this->employee($request, $filters['employee_id'] ?? null);

        $now = today();
        $presented = $this->presenter->month($this->attendance->month(
            $employee,
            (int) ($filters['year'] ?? $now->year),
            (int) ($filters['month'] ?? $now->month),
        ));

        return response()->json([
            'employee' => ['id' => $employee->id, 'name' => $employee->name, 'employee_code' => $employee->employee_code],
            ...$presented,
        ]);
    }

    public function today(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
        ]);

        $employee = $this->employee($request, $filters['employee_id'] ?? null);

        return response()->json([
            'employee' => ['id' => $employee->id, 'name' => $employee->name, 'employee_code' => $employee->employee_code],
            ...$this->presenter->today($this->attendance->today($employee)),
        ]);
    }

    /**
     * One employee's days as CSV, capped at a quarter.
     *
     * An unbounded export is a full-table read behind a button; 93 days
     * covers a quarter, which is the widest honest "give me the quarter"
     * anyone asks for. The download writes an access row like any other
     * bulk read of working-time data.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $employee = $this->employee($request, $filters['employee_id'] ?? null);

        $from = Carbon::parse($filters['from'])->startOfDay();
        $to = Carbon::parse($filters['to'])->startOfDay();

        if ($from->diffInDays($to) > 93) {
            throw ValidationException::withMessages(['to' => 'Exports cover at most 93 days. Narrow the range.']);
        }

        $rows = AttendanceDay::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $to->toDateString())
            ->orderBy('work_date')
            ->get();

        $this->audit->accessed(
            (new AttendanceDay)->getMorphClass(),
            $employee->id,
            DataAccessAction::Export,
            ['work_date', 'status', 'first_in_at', 'last_out_at', 'worked_minutes'],
            $request->user(),
            $request->ip(),
        );

        $filename = "attendance-{$employee->employee_code}-{$from->toDateString()}_{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['date', 'status', 'first_in', 'last_out', 'worked_minutes', 'break_minutes', 'late_by_minutes', 'early_by_minutes', 'overtime_minutes', 'regularized']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->work_date->toDateString(),
                    $row->status->value,
                    $row->first_in_at?->toDateTimeString(),
                    $row->last_out_at?->toDateTimeString(),
                    $row->worked_minutes,
                    $row->break_minutes,
                    $row->late_by_minutes,
                    $row->early_by_minutes,
                    $row->overtime_minutes,
                    $row->is_regularized ? 'yes' : 'no',
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The named employee, or the caller's own record.
     *
     * A login with no employment record gets a 404 naming the missing
     * record, not a 500 inside the readers (the P5.3 punch lesson).
     */
    private function employee(Request $request, ?int $employeeId): Employee
    {
        $employee = $employeeId === null
            ? Employee::where('user_id', $request->user()->id)->first()
            : Employee::find($employeeId);

        abort_if($employee === null, 404, 'There is no employment record to read attendance for.');

        $this->authorize('view', [AttendanceDay::class, $employee]);

        return $employee;
    }
}
