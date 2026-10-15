<?php

namespace App\Http\Controllers\Hrms\Org;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Org\LocationRequest;
use App\Http\Requests\Hrms\Org\OrgReorderRequest;
use App\Models\Hrms\Org\Location;
use App\Services\Hrms\Org\LocationService;
use App\Services\Hrms\Org\OrgPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Org/HRMS — the location HTTP surface.
 *
 * Thin (D2.12/D2.16), and for the same reason as {@see DepartmentController}:
 * it authorizes, delegates, shapes. The guards that make this record fussy — a
 * location in use cannot be deleted, a geofence needs all three of its parts —
 * belong to {@see LocationService}, where the seeder and the console command can
 * reach them too.
 */
class LocationController extends Controller
{
    public function __construct(
        private readonly LocationService $locations,
        private readonly OrgPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Location::class);

        return response()->json([
            'locations' => $this->locations->all()
                ->map(fn (Location $location): array => $this->presenter->location($location))
                ->all(),
        ]);
    }

    public function show(Location $location): JsonResponse
    {
        $this->authorize('view', $location);

        $location->loadCount('employees');

        return response()->json([
            'location' => $this->presenter->location($location),
        ]);
    }

    public function store(LocationRequest $request): JsonResponse
    {
        $this->authorize('create', Location::class);

        $location = $this->locations->create($request->validated());

        return response()->json([
            'message' => 'Location created.',
            'location' => $this->presenter->location($location),
        ], Response::HTTP_CREATED);
    }

    public function update(LocationRequest $request, Location $location): JsonResponse
    {
        $this->authorize('update', $location);

        $updated = $this->locations->update($location, $request->validated());

        return response()->json([
            'message' => 'Location updated.',
            'location' => $this->presenter->location($updated),
        ]);
    }

    public function destroy(Location $location): JsonResponse
    {
        $this->authorize('delete', $location);

        $this->locations->delete($location);

        return response()->json(['message' => 'Location deleted.']);
    }

    /**
     * Retire a location without deleting it.
     *
     * Separate from delete because a delete is refused while a location is in use,
     * and a client needs somewhere to go for "we retired this". Keeping the row
     * is what lets an old payslip still name the location it was issued against.
     */
    public function deactivate(Location $location): JsonResponse
    {
        $this->authorize('update', $location);

        $deactivated = $this->locations->deactivate($location);

        return response()->json([
            'message' => 'Location deactivated.',
            'location' => $this->presenter->location($deactivated),
        ]);
    }

    public function reorder(OrgReorderRequest $request): JsonResponse
    {
        $this->authorize('reorder', Location::class);

        $this->locations->reorder($request->ids());

        return response()->json(['message' => 'locations reordered.']);
    }
}
