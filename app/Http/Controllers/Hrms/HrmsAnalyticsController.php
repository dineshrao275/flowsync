<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Services\Hrms\HrmsAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Analytics/HRMS — the workforce dashboards over HTTP.
 *
 * Thin: it validates the shared filters (org ids against the tenant's own
 * tables, so a foreign id 422s instead of silently scoping to nothing),
 * hands the read to the service, and shapes nothing — the readers return
 * client-ready arrays. Each route carries its own gate in `routes/web.php`
 * (never a blanket `workspaces.view`, the Phase 7 precedent); this file
 * only decides the privacy tiers inside shared endpoints: ratings ride
 * talent viewers, liability rides payroll runners, and payroll logs every
 * call with its actor.
 */
class HrmsAnalyticsController extends Controller
{
    public function __construct(private readonly HrmsAnalyticsService $analytics) {}

    public function overview(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->json([
            'headcount' => $this->analytics->headcount($filters),
            'attendance' => $this->analytics->attendance($filters, false),
            'leave' => $this->analytics->leave($filters, false),
            'lifecycle' => $this->analytics->lifecycle($filters),
            'performance' => $this->analytics->performance($filters, $request->user()->hasPermission('hrms.talent.view')),
            'documents' => $this->analytics->documents($filters, false),
            'assets' => $this->analytics->assets($filters),
        ]);
    }

    public function attendance(Request $request): JsonResponse
    {
        return response()->json(
            $this->analytics->attendance($this->filters($request)),
        );
    }

    public function leave(Request $request): JsonResponse
    {
        return response()->json(
            $this->analytics->leave(
                $this->filters($request),
                true,
                $request->user()->hasPermission('hrms.payroll.run'),
            ),
        );
    }

    public function lifecycle(Request $request): JsonResponse
    {
        return response()->json(
            $this->analytics->lifecycle($this->filters($request)),
        );
    }

    public function performance(Request $request): JsonResponse
    {
        return response()->json(
            $this->analytics->performance(
                $this->filters($request),
                $request->user()->hasPermission('hrms.talent.view'),
            ),
        );
    }

    public function payroll(Request $request): JsonResponse
    {
        return response()->json(
            $this->analytics->payroll($this->filters($request), $request->user()),
        );
    }

    public function documents(Request $request): JsonResponse
    {
        return response()->json(
            $this->analytics->documents(
                $this->filters($request),
                true,
                $request->user()->hasPermission('hrms.documents.view_sensitive'),
            ),
        );
    }

    public function assets(Request $request): JsonResponse
    {
        return response()->json(
            $this->analytics->assets($this->filters($request)),
        );
    }

    /**
     * The shared window and scope: dates bound the reads, org ids scope
     * them — each validated against the tenant's own tables, because a
     * filter naming a row that is not ours must 422, never silently empty
     * the dashboard.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'employment_type_id' => ['sometimes', 'nullable', 'integer', 'exists:employment_types,id'],
            'manager_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:asset_categories,id'],
        ]);

        return array_filter($validated, fn ($value) => $value !== null);
    }
}
