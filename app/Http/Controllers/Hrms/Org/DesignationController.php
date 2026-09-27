<?php

namespace App\Http\Controllers\Hrms\Org;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Org\DesignationRequest;
use App\Http\Requests\Hrms\Org\OrgReorderRequest;
use App\Models\Hrms\Org\Designation;
use App\Services\Hrms\Org\DesignationService;
use App\Services\Hrms\Org\OrgPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Org/HRMS — the designation HTTP surface.
 *
 * Thin (D2.12/D2.16), and for the same reason as {@see DepartmentController}:
 * it authorizes, delegates, shapes. The guards that make this record fussy — a
 * designation in use cannot be deleted, a geofence needs all three of its parts —
 * belong to {@see DesignationService}, where the seeder and the console command can
 * reach them too.
 */
class DesignationController extends Controller
{
    public function __construct(
        private readonly DesignationService $designations,
        private readonly OrgPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Designation::class);

        return response()->json([
            'designations' => $this->designations->all()
                ->map(fn (Designation $designation): array => $this->presenter->designation($designation))
                ->all(),
        ]);
    }

    public function show(Designation $designation): JsonResponse
    {
        $this->authorize('view', $designation);

        $designation->loadCount('employees');

        return response()->json([
            'designation' => $this->presenter->designation($designation),
        ]);
    }

    public function store(DesignationRequest $request): JsonResponse
    {
        $this->authorize('create', Designation::class);

        $designation = $this->designations->create($request->validated());

        return response()->json([
            'message' => 'Designation created.',
            'designation' => $this->presenter->designation($designation),
        ], Response::HTTP_CREATED);
    }

    public function update(DesignationRequest $request, Designation $designation): JsonResponse
    {
        $this->authorize('update', $designation);

        $updated = $this->designations->update($designation, $request->validated());

        return response()->json([
            'message' => 'Designation updated.',
            'designation' => $this->presenter->designation($updated),
        ]);
    }

    public function destroy(Designation $designation): JsonResponse
    {
        $this->authorize('delete', $designation);

        $this->designations->delete($designation);

        return response()->json(['message' => 'Designation deleted.']);
    }

    /**
     * Retire a designation without deleting it.
     *
     * Separate from delete because a delete is refused while a designation is in use,
     * and a client needs somewhere to go for "we retired this". Keeping the row
     * is what lets an old payslip still name the designation it was issued against.
     */
    public function deactivate(Designation $designation): JsonResponse
    {
        $this->authorize('update', $designation);

        $deactivated = $this->designations->deactivate($designation);

        return response()->json([
            'message' => 'Designation deactivated.',
            'designation' => $this->presenter->designation($deactivated),
        ]);
    }

    public function reorder(OrgReorderRequest $request): JsonResponse
    {
        $this->authorize('reorder', Designation::class);

        $this->designations->reorder($request->ids());

        return response()->json(['message' => 'designations reordered.']);
    }
}
