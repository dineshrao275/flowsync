<?php

namespace App\Http\Controllers\Hrms\Org;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Org\DepartmentRequest;
use App\Http\Requests\Hrms\Org\OrgReorderRequest;
use App\Models\Hrms\Org\Department;
use App\Services\Hrms\Org\DepartmentService;
use App\Services\Hrms\Org\DepartmentTree;
use App\Services\Hrms\Org\OrgPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Org/HRMS — the department HTTP surface.
 *
 * Thin (D2.12/D2.16): it authorizes, hands the payload to the service, shapes
 * the response. No rule about a department lives here — a controller-level `if`
 * is a rule the service layer cannot see, and therefore cannot enforce for the
 * seeder, the console command or the queue worker that will eventually need the
 * same behaviour. The cycle check and the active-head check are both in
 * {@see DepartmentService}, for exactly that reason.
 *
 * Reorder takes its list from a query parameter rather than the body, because
 * the sibling set it applies to is the body of the URL: the two together name
 * one list, and putting the ids anywhere else invites a client to reorder one
 * parent's children with another's ids.
 */
class DepartmentController extends Controller
{
    public function __construct(
        private readonly DepartmentService $departments,
        private readonly DepartmentTree $tree,
        private readonly OrgPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Department::class);

        return response()->json([
            'departments' => $this->departments->all()
                ->map(fn (Department $department): array => $this->presenter->department($department))
                ->all(),
        ]);
    }

    /**
     * One department, with the chain to the root.
     *
     * The breadcrumb travels with it: the org chart renders it beside the
     * selected node, and a second request for the same three rows is a round
     * trip to learn which of them is above the one on screen.
     */
    public function show(Department $department): JsonResponse
    {
        $this->authorize('view', $department);

        $department->load(['head'])->loadCount(['employees', 'children']);

        return response()->json([
            'department' => $this->presenter->department($department),
            'path' => collect($this->tree->pathTo($department))
                ->map(fn (Department $ancestor): array => [
                    'id' => $ancestor->id,
                    'name' => $ancestor->name,
                ])->all(),
        ]);
    }

    public function store(DepartmentRequest $request): JsonResponse
    {
        $this->authorize('create', Department::class);

        $department = $this->departments->create($request->validated());

        return response()->json([
            'message' => 'Department created.',
            'department' => $this->presenter->department($department),
        ], Response::HTTP_CREATED);
    }

    public function update(DepartmentRequest $request, Department $department): JsonResponse
    {
        $this->authorize('update', $department);

        $updated = $this->departments->update($department, $request->validated());

        return response()->json([
            'message' => 'Department updated.',
            'department' => $this->presenter->department($updated),
        ]);
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->authorize('delete', $department);

        $this->departments->delete($department);

        return response()->json(['message' => 'Department deleted.']);
    }

    /**
     * Retire a department without deleting it.
     *
     * A separate endpoint from delete because the two answer different
     * questions. Deleting an empty department removes a row; deactivating one
     * keeps every payslip, letter and attendance record that names it, which is
     * what somebody means by "we closed that team" — and the service refuses a
     * delete outright once a department has people, so a client has to be able
     * to reach for this instead.
     */
    public function deactivate(Department $department): JsonResponse
    {
        $this->authorize('update', $department);

        $deactivated = $this->departments->deactivate($department);

        return response()->json([
            'message' => 'Department deactivated.',
            'department' => $this->presenter->department($deactivated),
        ]);
    }

    /**
     * Reorder one sibling list.
     *
     * `parent_id` in the query decides *which* list: absent for the roots, a
     * department id for that department's children. The service intersects the
     * submitted ids with the real siblings, so a payload naming an id from
     * another parent cannot move it.
     */
    public function reorder(OrgReorderRequest $request): JsonResponse
    {
        $this->authorize('reorder', Department::class);

        $parent = $request->integer('parent_id') > 0
            ? Department::findOrFail($request->integer('parent_id'))
            : null;

        $this->departments->reorder($parent, $request->ids());

        return response()->json(['message' => 'Departments reordered.']);
    }
}
