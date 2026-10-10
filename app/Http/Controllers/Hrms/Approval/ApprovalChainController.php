<?php

namespace App\Http\Controllers\Hrms\Approval;

use App\Enums\Hrms\ApprovalStepMode;
use App\Enums\Hrms\ApproverType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Approval\ApprovalChainRequest;
use App\Models\Hrms\Shared\ApprovalTemplate;
use App\Models\Role;
use App\Services\Hrms\Approval\ApprovalSla;
use App\Services\Hrms\Approval\ChainTemplates;
use Illuminate\Http\JsonResponse;

/**
 * Approval/HRMS — view and edit the approval chain of each domain (P2.4).
 *
 * Validate → authorize → one service call → present. Changing a chain only
 * affects approvals requested afterwards: in-flight approvals keep the steps
 * they were opened with (their rows carry the chain, not a template id).
 */
class ApprovalChainController extends Controller
{
    public function __construct(private readonly ChainTemplates $templates) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', ApprovalTemplate::class);

        return response()->json([
            'chains' => collect($this->templates->domains())
                ->keys()
                ->map(fn (string $domain): array => $this->present($domain))
                ->values(),
            'options' => [
                'roles' => Role::query()->orderBy('name')->get(['slug', 'name']),
                'step_types' => collect(ApproverType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
                'modes' => collect(ApprovalStepMode::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()]),
                'operators' => config('approvals.operators'),
                'default_reminder_hours' => ApprovalSla::DEFAULT_REMINDER_HOURS,
            ],
        ]);
    }

    public function update(ApprovalChainRequest $request, string $domain): JsonResponse
    {
        $this->authorize('update', ApprovalTemplate::class);
        abort_unless($this->templates->knows($domain), 404);

        $this->templates->save($domain, $request->validated(), $request->user());

        return response()->json(['message' => 'Approval chain saved.', 'chain' => $this->present($domain)]);
    }

    public function reset(string $domain): JsonResponse
    {
        $this->authorize('update', ApprovalTemplate::class);
        abort_unless($this->templates->knows($domain), 404);

        $this->templates->reset($domain, request()->user());

        return response()->json(['message' => 'Approval chain reset to the default.', 'chain' => $this->present($domain)]);
    }

    /** @return array<string, mixed> */
    private function present(string $domain): array
    {
        $definition = $this->templates->domains()[$domain];

        return $this->templates->for($domain) + [
            'label' => $definition['label'],
            'fields' => $definition['fields'],
            'default_steps' => $definition['steps'],
        ];
    }
}
