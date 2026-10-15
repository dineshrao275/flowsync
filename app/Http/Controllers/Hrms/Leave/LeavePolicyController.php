<?php

namespace App\Http\Controllers\Hrms\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Leave\LeavePolicyRequest;
use App\Models\Hrms\Leave\LeavePolicy;
use App\Services\Hrms\Leave\LeaveCatalogService;
use App\Services\Hrms\Leave\LeavePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Leave/HRMS — leave policies over HTTP.
 *
 * Same shape as the type catalogue: employee-open reads,
 * `hrms.leave.manage` writes, the default-promotion transaction in the
 * service. Promoting a policy demotes the previous default in the same
 * transaction, so the tenant never holds two.
 */
class LeavePolicyController extends Controller
{
    public function __construct(
        private readonly LeaveCatalogService $catalog,
        private readonly LeavePresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', LeavePolicy::class);

        return response()->json([
            'leave_policies' => $this->catalog->policies()
                ->map(fn (LeavePolicy $policy): array => $this->presenter->policy($policy))
                ->all(),
        ]);
    }

    public function store(LeavePolicyRequest $request): JsonResponse
    {
        $this->authorize('create', LeavePolicy::class);

        $policy = $this->catalog->createPolicy($request->validated(), $request->user());

        return response()->json([
            'message' => 'Leave policy created.',
            'leave_policy' => $this->presenter->policy($policy),
        ], Response::HTTP_CREATED);
    }

    public function update(LeavePolicyRequest $request, LeavePolicy $leavePolicy): JsonResponse
    {
        $this->authorize('update', $leavePolicy);

        $policy = $this->catalog->updatePolicy($leavePolicy, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Leave policy updated.',
            'leave_policy' => $this->presenter->policy($policy),
        ]);
    }

    public function destroy(Request $request, LeavePolicy $leavePolicy): JsonResponse
    {
        $this->authorize('delete', $leavePolicy);

        $this->catalog->deletePolicy($leavePolicy, $request->user());

        return response()->json(['message' => 'Leave policy deleted.']);
    }
}
