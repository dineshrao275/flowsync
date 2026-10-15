<?php

namespace App\Http\Controllers\Hrms\Holiday;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Holiday\HolidayAssignmentRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Holiday\EmployeeHolidayCalendar;
use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Services\Hrms\Holiday\HolidayAssignments;
use App\Services\Hrms\Holiday\HolidayPresenter;
use App\Services\Hrms\Holiday\HolidayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Holiday/HRMS — assignments and the resolved per-employee view over HTTP.
 *
 * Assigning is HR administration (manage-only); the resolved grid answers
 * per employee with self-service — anyone reads their own year, anyone
 * else needs the view permission, because the grid names taken optionals
 * alongside public holidays.
 */
class HolidayAssignmentController extends Controller
{
    public function __construct(
        private readonly HolidayAssignments $assignments,
        private readonly HolidayService $holidays,
        private readonly HolidayPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', EmployeeHolidayCalendar::class);

        $rows = EmployeeHolidayCalendar::query()
            ->with(['employee:id,name,employee_code', 'calendar:id,name,slug'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'assignments' => $rows->map(fn (EmployeeHolidayCalendar $row): array => $this->presenter->assignment($row))->all(),
        ]);
    }

    public function store(HolidayAssignmentRequest $request): JsonResponse
    {
        $this->authorize('create', EmployeeHolidayCalendar::class);

        $validated = $request->validated();

        $assignment = $this->assignments->assign(
            Employee::findOrFail((int) $validated['employee_id']),
            HolidayCalendar::findOrFail((int) $validated['calendar_id']),
            (string) $validated['effective_from'],
            $validated['effective_to'] ?? null,
            $request->user(),
        );

        return response()->json([
            'message' => 'Calendar assigned.',
            'assignment' => $this->presenter->assignment($assignment),
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, EmployeeHolidayCalendar $assignment): JsonResponse
    {
        $this->authorize('delete', $assignment);

        $this->assignments->unassign($assignment, $request->user());

        return response()->json(['message' => 'Calendar unassigned.']);
    }

    public function resolved(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $employee = isset($filters['employee_id'])
            ? Employee::findOrFail((int) $filters['employee_id'])
            : Employee::where('user_id', $request->user()->id)->first();

        abort_if($employee === null, 404, 'There is no employment record to resolve holidays for.');

        $this->authorize('resolved', [EmployeeHolidayCalendar::class, $employee]);

        $year = (int) ($filters['year'] ?? today()->year);

        return response()->json([
            'employee' => ['id' => $employee->id, 'name' => $employee->name, 'employee_code' => $employee->employee_code],
            ...$this->presenter->resolved($year, $this->holidays->calendar($employee, $year)),
        ]);
    }
}
