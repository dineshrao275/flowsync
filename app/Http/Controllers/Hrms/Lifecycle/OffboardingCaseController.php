<?php

namespace App\Http\Controllers\Hrms\Lifecycle;

use App\Enums\Hrms\OffboardingCaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Lifecycle\CompleteTaskRequest;
use App\Http\Requests\Hrms\Lifecycle\OffboardingCaseRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\Hrms\Lifecycle\OffboardingCaseTask;
use App\Services\Hrms\Lifecycle\DocumentRequestService;
use App\Services\Hrms\Lifecycle\LifecyclePresenter;
use App\Services\Hrms\OffboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Lifecycle/HRMS — offboarding cases over HTTP.
 *
 * Thin by design: it authorizes, hands the payload to the service, shapes
 * the response. The `clear` endpoint is the one place the strictness shows —
 * the policy takes manage and nothing else, and the service refuses over
 * open items with the blockers named, so the screen can make them
 * unmissable.
 */
class OffboardingCaseController extends Controller
{
    public function __construct(
        private readonly OffboardingService $offboarding,
        private readonly DocumentRequestService $requests,
        private readonly LifecyclePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OffboardingCase::class);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(OffboardingCaseStatus::class)],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
        ]);

        $cases = $this->offboarding->casesFor($request->user(), $this->clean($filters));

        return response()->json([
            'cases' => $cases->map(fn (OffboardingCase $case): array => $this->present($case))->all(),
        ]);
    }

    public function store(OffboardingCaseRequest $request): JsonResponse
    {
        $this->authorize('create', OffboardingCase::class);

        $validated = $request->validated();
        $case = $this->offboarding->initiate(
            Employee::findOrFail((int) $validated['employee_id']),
            $validated['last_working_day'],
            $validated['reason'],
            $validated['notice_period_days'] ?? null,
            $request->user(),
        );

        return response()->json([
            'message' => 'Exit run opened.',
            'case' => $this->present($case),
        ], Response::HTTP_CREATED);
    }

    public function show(OffboardingCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        return response()->json([
            'case' => $this->present($case),
        ]);
    }

    public function completeTask(CompleteTaskRequest $request, OffboardingCase $case, OffboardingCaseTask $task): JsonResponse
    {
        $this->authorize('completeTask', $task);
        $this->belonging($case, $task);

        $task = $this->offboarding->completeTask($task, $request->user(), $request->validated()['note'] ?? null);

        return response()->json([
            'message' => 'Checklist item completed.',
            'task' => $this->presenter->caseTask($task),
        ]);
    }

    /**
     * Sign the exit off. Refuses with the blockers named while anything is
     * outstanding — a 200 here is a signature, so it must never be the
     * answer to an unclear exit.
     */
    public function clear(Request $request, OffboardingCase $case): JsonResponse
    {
        $this->authorize('clear', $case);

        $clearance = $this->offboarding->clear($case, $request->user());

        return response()->json([
            'message' => 'Exit cleared.',
            'clearance' => $this->presenter->clearance($clearance),
        ]);
    }

    public function complete(Request $request, OffboardingCase $case): JsonResponse
    {
        $this->authorize('complete', $case);

        $case = $this->offboarding->complete($case, $request->user());

        return response()->json([
            'message' => 'Exit run completed.',
            'case' => $this->present($case),
        ]);
    }

    public function cancel(Request $request, OffboardingCase $case): JsonResponse
    {
        $this->authorize('cancel', $case);

        $case = $this->offboarding->cancel($case, $request->user());

        return response()->json([
            'message' => 'Exit run cancelled.',
            'case' => $this->present($case),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OffboardingCase $case): array
    {
        return $this->presenter->offboardingCase(
            $case,
            $this->offboarding->summary($case),
            $this->requests->forCase($case),
        );
    }

    /**
     * The nested-param rule: a task id from another case 404s here rather
     * than completing the wrong exit’s checklist.
     */
    private function belonging(OffboardingCase $case, OffboardingCaseTask $task): void
    {
        abort_if((int) $task->case_id !== (int) $case->id, 404);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function clean(array $filters): array
    {
        return array_filter($filters, fn (mixed $value) => $value !== null && $value !== '');
    }
}
