<?php

namespace App\Http\Controllers\Hrms\Lifecycle;

use App\Enums\Hrms\OnboardingCaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Lifecycle\CompleteTaskRequest;
use App\Http\Requests\Hrms\Lifecycle\ConvertCaseTaskRequest;
use App\Http\Requests\Hrms\Lifecycle\OnboardingCaseRequest;
use App\Http\Requests\Hrms\Lifecycle\WaiveTaskRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OnboardingCase;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Hrms\Lifecycle\OnboardingTemplate;
use App\Models\Project;
use App\Services\Hrms\Lifecycle\CaseTaskConversion;
use App\Services\Hrms\Lifecycle\DocumentRequestService;
use App\Services\Hrms\Lifecycle\LifecyclePresenter;
use App\Services\Hrms\OnboardingService;
use App\Services\Hrms\TaskLinkPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Lifecycle/HRMS — onboarding cases over HTTP.
 *
 * Thin by design: it authorizes, hands the payload to the service, shapes
 * the response. Task routes declare both models and verify the task belongs
 * to the case, else 404 — a task id from another hire’s run must never
 * complete this one.
 */
class OnboardingCaseController extends Controller
{
    public function __construct(
        private readonly OnboardingService $onboarding,
        private readonly DocumentRequestService $requests,
        private readonly LifecyclePresenter $presenter,
        private readonly CaseTaskConversion $conversion,
        private readonly TaskLinkPresenter $links,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OnboardingCase::class);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(OnboardingCaseStatus::class)],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
        ]);

        $cases = $this->onboarding->casesFor($request->user(), $this->clean($filters));

        return response()->json([
            'cases' => $cases->map(fn (OnboardingCase $case): array => $this->presenter->onboardingCase(
                $case,
                $this->onboarding->progress($case),
                $this->requests->forCase($case),
            ))->all(),
        ]);
    }

    public function store(OnboardingCaseRequest $request): JsonResponse
    {
        $this->authorize('create', OnboardingCase::class);

        $validated = $request->validated();
        $case = $this->onboarding->createCase(
            Employee::findOrFail((int) $validated['employee_id']),
            OnboardingTemplate::findOrFail((int) $validated['template_id']),
            $request->user(),
        );

        return response()->json([
            'message' => 'Onboarding case started.',
            'case' => $this->presenter->onboardingCase(
                $case,
                $this->onboarding->progress($case),
                $this->requests->forCase($case),
            ),
        ], Response::HTTP_CREATED);
    }

    public function show(OnboardingCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        return response()->json([
            'case' => $this->presenter->onboardingCase(
                $case,
                $this->onboarding->progress($case),
                $this->requests->forCase($case),
            ),
        ]);
    }

    public function completeTask(CompleteTaskRequest $request, OnboardingCase $case, OnboardingCaseTask $task): JsonResponse
    {
        $this->authorize('completeTask', $task);
        $this->belonging($case, $task);

        $task = $this->onboarding->completeTask($task, $request->user(), $request->validated()['note'] ?? null);

        return response()->json([
            'message' => 'Checklist item completed.',
            'task' => $this->presenter->caseTask($task),
        ]);
    }

    public function waiveTask(WaiveTaskRequest $request, OnboardingCase $case, OnboardingCaseTask $task): JsonResponse
    {
        $this->authorize('waiveTask', $task);
        $this->belonging($case, $task);

        $task = $this->onboarding->waiveTask($task, $request->validated()['reason'], $request->user());

        return response()->json([
            'message' => 'Checklist item waived.',
            'task' => $this->presenter->caseTask($task),
        ]);
    }

    /**
     * File this item as a project task: the item's title, description and
     * due date become the task (assigned to the owner's login when that
     * login sits on the project), and a link records the conversion.
     * Working the item plus filing into the project — both abilities, one
     * endpoint, because a conversion is neither without the other.
     */
    public function convertTask(ConvertCaseTaskRequest $request, OnboardingCase $case, OnboardingCaseTask $task): JsonResponse
    {
        $this->authorize('completeTask', $task);
        $this->belonging($case, $task);

        $project = Project::findOrFail((int) $request->validated()['project_id']);
        $this->authorize('createTask', $project);

        ['task' => $projectTask, 'link' => $link] = $this->conversion->convert($task, $project, $request->user(), $request->ip());

        return response()->json([
            'message' => 'Checklist item converted to a project task.',
            'task' => $this->presenter->caseTask($task->refresh()),
            'project_task' => ['id' => $projectTask->id, 'key' => $projectTask->key, 'title' => $projectTask->title],
            'task_link' => $this->links->present($link),
        ], Response::HTTP_CREATED);
    }

    /**
     * Pull the linked task's completion into the item: a done task closes
     * it through the normal completion path, anything else is a no-op.
     * Nothing here writes to the task — sync moves toward done only.
     */
    public function syncTask(Request $request, OnboardingCase $case, OnboardingCaseTask $task): JsonResponse
    {
        $this->authorize('completeTask', $task);
        $this->belonging($case, $task);

        $synced = $this->conversion->syncFromTask($task, $request->user());

        return response()->json([
            'message' => $synced ? 'Checklist item completed from the project task.' : 'Nothing to sync.',
            'task' => $this->presenter->caseTask($task->refresh()),
        ]);
    }

    public function complete(Request $request, OnboardingCase $case): JsonResponse
    {
        $this->authorize('complete', $case);

        $case = $this->onboarding->complete($case, $request->user());

        return response()->json([
            'message' => 'Onboarding case completed.',
            'case' => $this->presenter->onboardingCase(
                $case,
                $this->onboarding->progress($case),
                $this->requests->forCase($case),
            ),
        ]);
    }

    public function cancel(Request $request, OnboardingCase $case): JsonResponse
    {
        $this->authorize('cancel', $case);

        $case = $this->onboarding->cancel($case, $request->user());

        return response()->json([
            'message' => 'Onboarding case cancelled.',
            'case' => $this->presenter->onboardingCase(
                $case,
                $this->onboarding->progress($case),
                $this->requests->forCase($case),
            ),
        ]);
    }

    /**
     * The nested-param rule: a task id from another case 404s here rather
     * than completing the wrong hire’s checklist.
     */
    private function belonging(OnboardingCase $case, OnboardingCaseTask $task): void
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
