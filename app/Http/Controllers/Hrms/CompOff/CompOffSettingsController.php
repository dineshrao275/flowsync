<?php

namespace App\Http\Controllers\Hrms\CompOff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\CompOff\CompOffSettingsRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Services\Hrms\CompOff\CompOffCredits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * CompOff/HRMS — the accrual policy and on-demand runs.
 *
 * Manage-only (the route says so): the settings section merges per key so
 * tuning validity never blanks the weekend flag beside it, and the run
 * credits through the same idempotent loop as the command — same dates,
 * same rows, no second implementation.
 */
class CompOffSettingsController extends Controller
{
    public function __construct(private readonly CompOffCredits $credits) {}

    public function show(): JsonResponse
    {
        $settings = HrmsSetting::current();

        return response()->json([
            'comp_off' => $settings->comp_off ?? [
                'from_weekends' => true,
                'from_holidays' => true,
                'validity_months' => 3,
            ],
        ]);
    }

    public function update(CompOffSettingsRequest $request): JsonResponse
    {
        $settings = HrmsSetting::current();
        $validated = $request->validated();

        $settings->comp_off = array_merge($settings->comp_off ?? [], $validated);
        $settings->save();

        return response()->json([
            'message' => 'Comp-off settings saved.',
            'comp_off' => $settings->comp_off,
        ]);
    }

    public function accrue(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $employees = isset($filters['employee_id'])
            ? Employee::whereKey((int) $filters['employee_id'])->get()
            : Employee::query()->active()->orderBy('id')->get();

        $credited = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            $result = $this->credits->creditFromCalendar($employee, (string) $filters['from'], (string) $filters['to'], $request->user());
            $credited += $result['credited'];
            $skipped += $result['skipped'];
        }

        return response()->json([
            'message' => "Accrual run finished: {$credited} credited, {$skipped} skipped.",
            'employees' => $employees->count(),
            'credited' => $credited,
            'skipped' => $skipped,
        ], Response::HTTP_CREATED);
    }
}
