<?php

namespace App\Http\Controllers\Hrms\Org;

use App\Http\Controllers\Controller;
use App\Models\Hrms\Employee\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The reporting-line chart (F7.10): who reports to whom, from `employees.manager_id`. Only names, job titles and
 * departments leave here — the same facts the directory shows every reader — never personal or pay fields.
 * Anyone with the employee directory may see it; a dangling or cyclic manager link is surfaced as a root instead of
 * being dropped, so nobody vanishes from the chart.
 */
class OrgChartController extends Controller
{
    private const CAP = 2000;

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('hrms.employees.view') || $request->user()->hasPermission('hrms.org.view'), 403);

        $people = Employee::query()->active()
            ->with(['department:id,name', 'designation:id,name'])
            ->orderBy('name')->limit(self::CAP)->get(['id', 'name', 'employee_code', 'manager_id', 'department_id', 'designation_id', 'designation']);

        $ids = $people->pluck('id')->flip();

        return response()->json([
            'nodes' => $people->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'title' => $e->getRelation('designation')?->name ?? $e->getAttribute('designation'),
                'department' => $e->department?->name,
                // A manager who is not in the (active, capped) set makes this person a root.
                'manager_id' => $e->manager_id !== null && $ids->has($e->manager_id) && $e->manager_id !== $e->id ? $e->manager_id : null,
            ])->values(),
            'truncated' => $people->count() >= self::CAP,
        ]);
    }
}
