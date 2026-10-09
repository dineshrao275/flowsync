<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\DocumentVersionRequest;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Services\Hrms\Document\DocumentPresenter;
use App\Services\Hrms\Document\DocumentVersioning;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Document/HRMS — a document's version history, and replacing it.
 *
 * Reading the history asks the ordinary `view` policy of the row you opened (an
 * unreadable or confidential-invisible row 403s before any version is named);
 * replacing asks `upload` against the owner, exactly like filing a new file.
 */
class DocumentVersionController extends Controller
{
    public function __construct(
        private readonly DocumentVersioning $versions,
        private readonly DocumentPresenter $presenter,
    ) {}

    public function index(Request $request, EmployeeDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        return response()->json([
            'versions' => $this->versions->history($document)
                ->map(fn (EmployeeDocument $d): array => $this->presenter->present($d, $request->user()))
                ->all(),
        ]);
    }

    public function store(DocumentVersionRequest $request, EmployeeDocument $document): JsonResponse
    {
        $this->authorize('upload', [EmployeeDocument::class, $document->employee]);

        $next = $this->versions->addVersion($document, $request->file('file'), $request->safe()->except('file'), $request->user());

        return response()->json([
            'message' => "Version {$next->version} uploaded.",
            'document' => $this->presenter->present($next, $request->user()),
        ], Response::HTTP_CREATED);
    }
}
