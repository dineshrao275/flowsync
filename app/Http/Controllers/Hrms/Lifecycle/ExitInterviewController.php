<?php

namespace App\Http\Controllers\Hrms\Lifecycle;

use App\Enums\Hrms\OffboardingCaseStatus;
use App\Http\Controllers\Controller;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\ExitInterview;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Services\Hrms\Lifecycle\ExitInterviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Exit interviews. The leaver reads and fills in their own; HR (offboarding view/manage) reads any,
 * records one on someone's behalf, or waives it; insights are aggregated and anonymity-guarded.
 */
class ExitInterviewController extends Controller
{
    public function __construct(private readonly ExitInterviewService $service) {}

    public function mine(Request $request): JsonResponse
    {
        $employee = Employee::query()->where('user_id', $request->user()->id)->first();
        $case = $employee ? OffboardingCase::query()->where('employee_id', $employee->id)
            ->whereIn('status', [OffboardingCaseStatus::Initiated->value, OffboardingCaseStatus::InProgress->value, OffboardingCaseStatus::Completed->value])
            ->latest('id')->first() : null;

        if ($case === null) {
            return response()->json(['interview' => null, 'questions' => []]);
        }

        return response()->json(['interview' => $this->present($this->service->forCase($case)), 'questions' => $this->service->questions()]);
    }

    public function submitMine(Request $request): JsonResponse
    {
        $employee = Employee::query()->where('user_id', $request->user()->id)->first();
        $case = $employee ? OffboardingCase::query()->where('employee_id', $employee->id)->open()->latest('id')->first() : null;
        abort_if($case === null, 404, __('You have no exit in progress.'));

        $data = $request->validate(['answers' => ['required', 'array']]);

        return response()->json([
            'message' => __('Thank you — your exit interview was saved.'),
            'interview' => $this->present($this->service->submit($this->service->forCase($case), $data['answers'], $request->user())),
        ]);
    }

    public function show(Request $request, OffboardingCase $case): JsonResponse
    {
        $this->requireHr($request, 'view');

        return response()->json(['interview' => $this->present($this->service->forCase($case)), 'questions' => $this->service->questions()]);
    }

    public function record(Request $request, OffboardingCase $case): JsonResponse
    {
        $this->requireHr($request, 'manage');
        $data = $request->validate(['answers' => ['required', 'array']]);

        return response()->json([
            'message' => __('Exit interview recorded.'),
            'interview' => $this->present($this->service->submit($this->service->forCase($case), $data['answers'], $request->user())),
        ]);
    }

    public function waive(Request $request, OffboardingCase $case): JsonResponse
    {
        $this->requireHr($request, 'manage');

        return response()->json([
            'message' => __('Exit interview waived.'),
            'interview' => $this->present($this->service->waive($this->service->forCase($case), $request->user())),
        ]);
    }

    public function insights(Request $request): JsonResponse
    {
        $this->requireHr($request, 'view');

        return response()->json(['insights' => $this->service->insights()]);
    }

    private function requireHr(Request $request, string $level): void
    {
        abort_unless($request->user()->hasPermission("hrms.offboarding.{$level}"), 403, __('You cannot access exit interviews.'));
    }

    /** @return array<string, mixed> */
    private function present(ExitInterview $i): array
    {
        return [
            'id' => $i->id,
            'offboarding_case_id' => $i->offboarding_case_id,
            'status' => $i->status,
            'answers' => $i->answers ?? (object) [],
            'completed_at' => $i->completed_at?->toIso8601String(),
        ];
    }
}
