<?php

namespace App\Http\Controllers\Hrms\Holiday;

use App\Enums\Hrms\OptionalHolidayStatus;
use App\Http\Controllers\Concerns\NormalizesFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Holiday\HolidayOptionalRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Holiday\Holiday;
use App\Models\Hrms\Holiday\HolidayOptionalHoliday;
use App\Services\Hrms\Holiday\HolidayAssignments;
use App\Services\Hrms\Holiday\HolidayOptionalDirectory;
use App\Services\Hrms\Holiday\HolidayPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Holiday/HRMS — optional answers over HTTP.
 *
 * Declaring is an upsert keyed on the employee/holiday pair: answering
 * again replaces the answer, and the audit row records each declaration.
 * Anyone declares for themselves; managers file for others with `manage`.
 */
class HolidayOptionalController extends Controller
{
    use NormalizesFilters;

    public function __construct(
        private readonly HolidayAssignments $assignments,
        private readonly HolidayOptionalDirectory $directory,
        private readonly HolidayPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', HolidayOptionalHoliday::class);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(OptionalHolidayStatus::class)],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
        ]);

        $rows = $this->directory->listFor($request->user(), $this->clean($filters));

        return response()->json([
            'optionals' => $rows->map(fn (HolidayOptionalHoliday $row): array => $this->presenter->optional($row))->all(),
        ]);
    }

    public function store(HolidayOptionalRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $employee = isset($validated['employee_id'])
            ? Employee::findOrFail((int) $validated['employee_id'])
            : Employee::where('user_id', $request->user()->id)->first();

        abort_if($employee === null, 404, 'There is no employment record to declare for.');

        $this->authorize('create', [HolidayOptionalHoliday::class, $employee]);

        $answer = $this->assignments->declareOptional(
            $employee,
            Holiday::findOrFail((int) $validated['holiday_id']),
            OptionalHolidayStatus::from((string) $validated['status']),
            $validated['taken_date'] ?? null,
            $validated['note'] ?? null,
            $request->user(),
        );

        return response()->json([
            'message' => 'Optional holiday declared.',
            'optional' => $this->presenter->optional($answer),
        ], Response::HTTP_CREATED);
    }
}
