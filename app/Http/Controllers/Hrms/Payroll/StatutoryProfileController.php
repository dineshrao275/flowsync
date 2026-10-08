<?php

namespace App\Http\Controllers\Hrms\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\StatutoryProfileRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Statutory\StatutoryProfile;
use App\Services\Hrms\Statutory\StatutoryProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Statutory/HRMS — one person's identifiers, masked by default.
 *
 * Thin: it authorizes against the profile policy (reads are self or
 * manage, writes and the cleartext reveal are manage alone) and hands the
 * work to the service. A person with no profile reads as `profile: null`,
 * not a 404 — no row means nothing filed, not a missing record. The policy
 * authorizes against the employee, never the profile, for the same reason:
 * the row may not exist yet when the question is asked.
 */
class StatutoryProfileController extends Controller
{
    public function __construct(private readonly StatutoryProfileService $profiles) {}

    public function show(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('view', [StatutoryProfile::class, $employee]);

        $profile = StatutoryProfile::query()->where('employee_id', $employee->id)->first();

        return response()->json([
            'profile' => $profile === null ? null : $this->profiles->present($profile),
        ]);
    }

    public function update(StatutoryProfileRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', [StatutoryProfile::class, $employee]);

        $profile = $this->profiles->upsert($employee, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Statutory profile saved.',
            'profile' => $this->profiles->present($profile),
        ]);
    }

    /**
     * The cleartext read. Manage-gated, access-logged inside the service —
     * the one endpoint where identifiers travel unmasked, so it is a POST
     * (never cached, never prefetched) with its own audit trail.
     */
    public function reveal(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('reveal', [StatutoryProfile::class, $employee]);

        $profile = StatutoryProfile::query()->where('employee_id', $employee->id)->first();
        abort_if($profile === null, 404);

        return response()->json([
            'profile' => $this->profiles->reveal($profile, $request->user(), $request->ip()),
        ]);
    }
}
