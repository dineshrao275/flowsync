<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\AuditDataAccessRequest;
use App\Http\Requests\Hrms\AuditIndexRequest;
use App\Services\Hrms\Audit\AuditLogQuery;
use App\Services\Hrms\Audit\AuditPresenter;
use App\Services\Hrms\Audit\DataAccessQuery;
use Illuminate\Http\JsonResponse;

/**
 * Audit/HRMS — reading the append-only trail over HTTP.
 *
 * Thin: the request validates the filters, the route middleware owns the
 * `hrms.audit.view` gate (the permission IS the authorization — the ledger
 * is cross-cutting, so no per-record policy can answer for it), and the
 * query + presenter shape everything. Both endpoints are read-only; nothing
 * here can rewrite history, which is the point of the table.
 */
class AuditController extends Controller
{
    public function __construct(
        private readonly AuditLogQuery $query,
        private readonly DataAccessQuery $access,
        private readonly AuditPresenter $presenter,
    ) {}

    public function index(AuditIndexRequest $request): JsonResponse
    {
        $page = $this->query->paginate($request->filters());

        return response()->json([
            'audit_logs' => $page->getCollection()
                ->map(fn ($row) => $this->presenter->present($row))
                ->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function trail(string $subjectType, int $subjectId): JsonResponse
    {
        $subjectType = trim($subjectType);

        abort_if($subjectType === '' || strlen($subjectType) > 255, 422, 'Unknown subject.');

        $rows = $this->query->trail($subjectType, $subjectId);

        return response()->json([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'audit_logs' => $rows->map(fn ($row) => $this->presenter->present($row))->all(),
            'total' => $rows->count(),
        ]);
    }

    public function dataAccess(AuditDataAccessRequest $request): JsonResponse
    {
        $page = $this->access->paginate($request->filters());

        return response()->json([
            'data_access_logs' => $page->getCollection()
                ->map(fn ($row) => $this->presenter->presentAccess($row))
                ->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
