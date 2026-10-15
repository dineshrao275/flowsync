<?php

namespace App\Http\Controllers\Hrms;

use App\Enums\Hrms\DocumentStatus;
use App\Http\Controllers\Concerns\NormalizesFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\DocumentUploadRequest;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\DocumentService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Document/HRMS — the authenticated document surface.
 *
 * Thin by design (D2.12/D2.16): it authorizes, hands the payload to
 * {@see DocumentService}, and shapes the response. The per-record answers live
 * in the document policy — including reading one’s own files, which no tenant
 * permission grants — and the set-level answers live in the document
 * directory query the service delegates to.
 *
 * List filters are validated inline because P13.3 ships exactly two document
 * FormRequests (the type catalogue query and the upload); a third class for a
 * query string would be a form where no form exists.
 */
class DocumentController extends Controller
{
    use NormalizesFilters;

    public function __construct(private readonly DocumentService $documents) {}

    /**
     * Visible documents, scoped by the caller: directory readers see
     * everything non-confidential, the sensitive permission adds the
     * confidential rows, and anyone else sees only their own files.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EmployeeDocument::class);

        $filters = $request->validate($this->listRules());
        $page = $this->documents->listFor($request->user(), $this->clean($filters));

        return response()->json([
            'documents' => $this->presentPage($page, $request),
            'pagination' => $this->pagination($page),
        ]);
    }

    /**
     * File one document for an employment record.
     */
    public function store(DocumentUploadRequest $request): JsonResponse
    {
        $data = $request->validated();
        $employee = Employee::findOrFail((int) $data['employee_id']);
        $type = DocumentType::findOrFail((int) $data['document_type_id']);

        $this->authorize('upload', [EmployeeDocument::class, $employee]);

        $document = $this->documents->upload(
            $employee,
            $type,
            $data['file'],
            $request->safe()->except(['employee_id', 'document_type_id', 'file']),
            $request->user(),
        );

        return response()->json([
            'message' => 'Document uploaded.',
            'document' => $this->documents->present($document, $request->user()),
        ], Response::HTTP_CREATED);
    }

    /**
     * One row for its reader. A confidential read writes an access row inside
     * the service; an ordinary read writes nothing.
     */
    public function show(Request $request, EmployeeDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        return response()->json([
            'document' => $this->documents->show($request->user(), $document, $request->ip()),
        ]);
    }

    /**
     * Remove the file and hide the row.
     */
    public function destroy(Request $request, EmployeeDocument $document): JsonResponse
    {
        $this->authorize('delete', $document);

        $this->documents->delete($document, $request->user());

        return response()->json(['message' => 'Document deleted.']);
    }

    /**
     * Mark a reviewed document as evidence.
     */
    public function verify(Request $request, EmployeeDocument $document): JsonResponse
    {
        $this->authorize('verify', $document);

        $document = $this->documents->verify($document, $request->user());

        return response()->json([
            'message' => 'Document verified.',
            'document' => $this->documents->present($document, $request->user()),
        ]);
    }

    /**
     * Refuse a document, with the reason the employee can act on.
     */
    public function reject(Request $request, EmployeeDocument $document): JsonResponse
    {
        $this->authorize('reject', $document);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $document = $this->documents->reject($document, $validated['reason'], $request->user());

        return response()->json([
            'message' => 'Document rejected.',
            'document' => $this->documents->present($document, $request->user()),
        ]);
    }

    /**
     * Visible documents expiring within the window, for the compliance panel.
     *
     * Declared before `{document}` in the routes file: “expiring” as a bound
     * id would 404 a perfectly valid warning query.
     */
    public function expiring(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EmployeeDocument::class);

        $filters = $request->validate(array_merge($this->listRules(), [
            'days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
        ]));
        $filters = $this->clean($filters);
        $days = (int) ($filters['days'] ?? 30);
        unset($filters['days']);

        $page = $this->documents->expiringFor($request->user(), $days, $filters);

        return response()->json([
            'documents' => $this->presentPage($page, $request),
            'pagination' => $this->pagination($page),
            'days' => $days,
        ]);
    }

    /**
     * The caller’s own files. Self-scoped like notifications: no employment
     * record means an empty list, not a 404, because a login without a record
     * must not inherit anyone else’s files.
     *
     * `employee_id` travels alongside so the “My files” page can file a new
     * upload without a second lookup: there is no “my employee” endpoint, and
     * deriving it from the first row breaks the day the list is empty.
     */
    public function mine(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'document_type_id' => ['sometimes', 'nullable', 'integer', 'exists:document_types,id'],
            'status' => ['sometimes', 'nullable', Rule::enum(DocumentStatus::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->documents->mine($request->user(), $this->clean($filters));

        return response()->json([
            'documents' => $this->presentPage($page, $request),
            'pagination' => $this->pagination($page),
            'employee_id' => Employee::where('user_id', $request->user()->id)->value('id'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function listRules(): array
    {
        return [
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'document_type_id' => ['sometimes', 'nullable', 'integer', 'exists:document_types,id'],
            'status' => ['sometimes', 'nullable', Rule::enum(DocumentStatus::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function presentPage(LengthAwarePaginator $page, Request $request): array
    {
        return $page->getCollection()
            ->map(fn (EmployeeDocument $document): array => $this->documents->present($document, $request->user()))
            ->all();
    }

    private function pagination(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }
}
