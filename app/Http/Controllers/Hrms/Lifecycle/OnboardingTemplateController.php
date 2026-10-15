<?php

namespace App\Http\Controllers\Hrms\Lifecycle;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Lifecycle\OnboardingTemplateRequest;
use App\Http\Requests\Hrms\Lifecycle\OnboardingTemplateTaskRequest;
use App\Http\Requests\Hrms\Lifecycle\ReorderChecklistRequest;
use App\Models\Hrms\Lifecycle\OnboardingTemplate;
use App\Models\Hrms\Lifecycle\OnboardingTemplateTask;
use App\Services\Hrms\Lifecycle\LifecyclePresenter;
use App\Services\Hrms\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Lifecycle/HRMS — the onboarding template catalogue over HTTP.
 *
 * Thin by design: it authorizes, hands the payload to the service, shapes
 * the response. Nested routes declare both models (`templates/{template}`
 * *and* `tasks/{task}`) and verify the task belongs to the template, else
 * 404 — a task id from another template must never edit this one.
 */
class OnboardingTemplateController extends Controller
{
    public function __construct(
        private readonly OnboardingService $onboarding,
        private readonly LifecyclePresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', OnboardingTemplate::class);

        $templates = $this->onboarding->templates(false);

        return response()->json([
            'templates' => $templates->map(fn (OnboardingTemplate $template): array => $this->presenter->template($template))->all(),
        ]);
    }

    public function store(OnboardingTemplateRequest $request): JsonResponse
    {
        $this->authorize('create', OnboardingTemplate::class);

        $validated = $request->validated();
        $template = $this->onboarding->createTemplate(
            $validated['name'],
            $request->safe()->except('name'),
            $request->user(),
        );

        return response()->json([
            'message' => 'Template created.',
            'template' => $this->presenter->template($template),
        ], Response::HTTP_CREATED);
    }

    public function show(OnboardingTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);

        return response()->json([
            'template' => $this->presenter->template($template),
        ]);
    }

    public function update(OnboardingTemplateRequest $request, OnboardingTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        $validated = $request->validated();
        $template = $this->onboarding->updateTemplate(
            $template,
            $validated['name'] ?? $template->name,
            $request->safe()->except('name'),
            $request->user(),
        );

        return response()->json([
            'message' => 'Template updated.',
            'template' => $this->presenter->template($template),
        ]);
    }

    public function destroy(OnboardingTemplate $template): JsonResponse
    {
        $this->authorize('delete', $template);

        $this->onboarding->deleteTemplate($template);

        return response()->json(['message' => 'Template deleted.']);
    }

    public function storeTask(OnboardingTemplateTaskRequest $request, OnboardingTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        $task = $this->onboarding->addTemplateTask($template, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Checklist item added.',
            'task' => $this->presenter->templateTask($task),
        ], Response::HTTP_CREATED);
    }

    public function updateTask(
        OnboardingTemplateTaskRequest $request,
        OnboardingTemplate $template,
        OnboardingTemplateTask $task,
    ): JsonResponse {
        $this->authorize('update', $template);
        $this->belonging($template, $task);

        $task = $this->onboarding->updateTemplateTask($task, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Checklist item updated.',
            'task' => $this->presenter->templateTask($task),
        ]);
    }

    public function destroyTask(OnboardingTemplate $template, OnboardingTemplateTask $task): JsonResponse
    {
        $this->authorize('update', $template);
        $this->belonging($template, $task);

        $this->onboarding->removeTemplateTask($task);

        return response()->json(['message' => 'Checklist item removed.']);
    }

    public function reorderTasks(ReorderChecklistRequest $request, OnboardingTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        $this->onboarding->reorderTemplateTasks($template, $request->validated()['ordered_ids']);

        return response()->json(['message' => 'Checklist reordered.']);
    }

    /**
     * The nested-param rule: a task id from another template 404s here
     * rather than editing the wrong checklist.
     */
    private function belonging(OnboardingTemplate $template, OnboardingTemplateTask $task): void
    {
        abort_if((int) $task->template_id !== (int) $template->id, 404);
    }
}
