<?php

namespace App\Http\Controllers\Hrms\Lifecycle;

use App\Enums\Hrms\DocumentRequestSource;
use App\Enums\Hrms\DocumentRequestStatus;
use App\Http\Controllers\Concerns\NormalizesFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Lifecycle\DocumentRequestReviewRequest;
use App\Http\Requests\Hrms\Lifecycle\DocumentRequestStoreRequest;
use App\Http\Requests\Hrms\Lifecycle\DocumentRequestSubmitRequest;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\DocumentRequest;
use App\Models\Hrms\Lifecycle\OnboardingCase;
use App\Services\Hrms\Lifecycle\DocumentRequestService;
use App\Services\Hrms\Lifecycle\LifecyclePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Lifecycle/HRMS — document asks over HTTP.
 *
 * One controller for both case types because a request is the same thing
 * wherever it is raised from. Case-linked asks are raised by the case
 * services when they materialise; this is the standalone surface — HR
 * raising a free ask, the employee submitting their file, HR answering it.
 */
class DocumentRequestController extends Controller
{
    use NormalizesFilters;

    public function __construct(
        private readonly DocumentRequestService $requests,
        private readonly LifecyclePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DocumentRequest::class);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(DocumentRequestStatus::class)],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'source' => ['sometimes', 'nullable', 'in:onboarding,offboarding,hr'],
        ]);

        $rows = $this->requests->indexFor($request->user(), $this->cleanBlank($filters));

        return response()->json([
            'requests' => $rows->map(fn (DocumentRequest $row): array => $this->presenter->request($row))->all(),
        ]);
    }

    public function store(DocumentRequestStoreRequest $request): JsonResponse
    {
        $this->authorize('create', DocumentRequest::class);

        $validated = $request->validated();
        $row = $this->requests->requestFor(
            Employee::findOrFail((int) $validated['employee_id']),
            $validated,
            DocumentRequestSource::Hr,
            $this->resolveCase($validated),
            $request->user(),
        );

        return response()->json([
            'message' => 'Document requested.',
            'request' => $this->presenter->request($row),
        ], Response::HTTP_CREATED);
    }

    public function show(DocumentRequest $documentRequest): JsonResponse
    {
        $this->authorize('view', $documentRequest);

        return response()->json([
            'request' => $this->presenter->request($documentRequest),
        ]);
    }

    public function submit(DocumentRequestSubmitRequest $request, DocumentRequest $documentRequest): JsonResponse
    {
        $this->authorize('submit', $documentRequest);

        $row = $this->requests->submit(
            $documentRequest,
            EmployeeDocument::findOrFail((int) $request->validated()['document_id']),
            $request->user(),
        );

        return response()->json([
            'message' => 'Document submitted.',
            'request' => $this->presenter->request($row),
        ]);
    }

    public function accept(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $this->authorize('review', $documentRequest);

        $row = $this->requests->accept($documentRequest, $request->user());

        return response()->json([
            'message' => 'Document accepted.',
            'request' => $this->presenter->request($row),
        ]);
    }

    public function waive(DocumentRequestReviewRequest $request, DocumentRequest $documentRequest): JsonResponse
    {
        $this->authorize('review', $documentRequest);

        $row = $this->requests->waive($documentRequest, $request->validated()['reason'], $request->user());

        return response()->json([
            'message' => 'Document request waived.',
            'request' => $this->presenter->request($row),
        ]);
    }

    public function reject(DocumentRequestReviewRequest $request, DocumentRequest $documentRequest): JsonResponse
    {
        $this->authorize('review', $documentRequest);

        $row = $this->requests->reject($documentRequest, $request->validated()['reason'], $request->user());

        return response()->json([
            'message' => 'Document rejected.',
            'request' => $this->presenter->request($row),
        ]);
    }

    /**
     * The optional case pair on a free ask. Resolved here rather than in the
     * service so the 404 names the missing case instead of arriving as a
     * silent null link — an ask “for a case” that points nowhere is worse
     * than no ask at all.
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveCase(array $validated): ?OnboardingCase
    {
        if (empty($validated['case_type']) || empty($validated['case_id'])) {
            return null;
        }

        // Standalone asks attach to onboarding cases only: an offboarding ask
        // is raised by the exit run itself, which already links its requests.
        // A second path to the same link is a second place to get it wrong.
        abort_unless($validated['case_type'] === 'onboarding', 422, 'Free asks attach to onboarding cases.');

        return OnboardingCase::findOrFail((int) $validated['case_id']);
    }
}
