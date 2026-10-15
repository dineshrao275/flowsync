<?php

namespace App\Http\Controllers\Hrms\Org;

use App\Http\Controllers\Controller;
use App\Models\Hrms\Org\Department;
use App\Services\Hrms\Org\DepartmentChart;
use App\Services\Hrms\Org\DepartmentService;
use App\Services\Hrms\Org\DesignationService;
use App\Services\Hrms\Org\LocationService;
use App\Services\Hrms\Org\OrgPresenter;
use Illuminate\Http\JsonResponse;

/**
 * Org/HRMS — the whole org in one request.
 *
 * The tree plus the two flat lists the detail column needs, because an org page
 * that fetches the chart and then a department, its designations and its
 * locations is four round trips before it can draw anything, and the third of
 * them is the slowest to arrive.
 *
 * Note what this endpoint does *not* do: it does not re-count anybody. The
 * chart's headcounts come from {@see DepartmentChart}'s grouped query, and the
 * flat lists bring their own `withCount`, so the whole response is three
 * queries regardless of how many departments the tenant has.
 */
class OrgController extends Controller
{
    public function __construct(
        private readonly DepartmentChart $chart,
        private readonly DepartmentService $departments,
        private readonly DesignationService $designations,
        private readonly LocationService $locations,
        private readonly OrgPresenter $presenter,
    ) {}

    /**
     * The org chart, and the lists the page's detail column switches between.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Department::class);

        return response()->json([
            // Already nested with depth and both headcounts, computed in two
            // queries — not re-shaped here, because a second walk to produce a
            // payload that is already correct is a second place to be wrong.
            'tree' => $this->chart->build(),
            'departments' => $this->departments->all()
                ->map(fn ($department): array => $this->presenter->department($department))
                ->all(),
            'designations' => $this->designations->all()
                ->map(fn ($designation): array => $this->presenter->designation($designation))
                ->all(),
            'locations' => $this->locations->all()
                ->map(fn ($location): array => $this->presenter->location($location))
                ->all(),
        ]);
    }
}
